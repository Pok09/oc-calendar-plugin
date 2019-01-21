<?php namespace Captive\CalendarWidget\Widgets;

use Db;
use Log;
use Html;
use Lang;
use Backend;
use DbDongle;
use Response;
use Carbon\Carbon;
use October\Rain\Html\Helper as HtmlHelper;
use October\Rain\Router\Helper as RouterHelper;
use System\Helpers\DateTime as DateTimeHelper;
use System\Classes\PluginManager;
use Backend\Classes\ListColumn;
use Backend\Classes\WidgetBase;
use October\Rain\Database\Model;
use ApplicationException;
use Captive\CalendarWidget\Classes\Event as EventData;

class Calendar extends WidgetBase
{
    const PARTIAL_FILE = 'calendar';
    /**
     * @var Model Form model object.
     */
    public $model;

    /**
     * @var string Link for each record row. Replace :id with the record id.
     */
    public $recordUrl;

    /**
     * @var string Click event for each record row. Replace :id with the record id.
     */
    public $recordOnClick;

    public $recordId;
    public $recordTitle;

    public $recordStart;

    public $recordEnd;
    public $editable = false;

    private $displayModeDictionary = [
        'month'=> 'month',
        'week' => 'agendaWeek',
        'day'  => 'agendaDay',
        'list' => 'listMonth'
    ];

    public $availableDisplayModes = [];

    /**
     * @var array Calendar of CSS classes to apply to the Calendar container element
     */
    public $cssClasses = [];
        /**
     * @var array Collection of functions to apply to each list query.
     */
    protected $filterCallbacks = [];


    /**
     * @var string The context of this form, fields that do not belong
     * to this context will not be shown.
     */
    public $context;

    /**
     * @inheritDoc
     */
    protected $defaultAlias = 'calendar';

    /**
     * @var string Active session key, used for editing forms and deferred bindings.
     */
    public $sessionKey;

    /**
     * @var bool Render this form with uneditable preview data.
     */
    public $previewMode = false;

    /**
     * @var \Backend\Classes\WidgetManager
     */
    protected $widgetManager;

    public $searchTerm;
    public $searchMode;
    public $searchScope;

    /**
     * Column config from columns.yaml
     *
     * @var array
     */
    public $columns;

    public $searchableColumns = null;
    public $visiableColumns = null;

    /**
     * @inheritDoc
     */
    public function init()
    {
        //model/xxx/calendar.yaml
        $this->fillFromConfig([
            'columns',
            'recordUrl',
            'recordOnClick',
            'recordId',
            'recordTitle',
            'recordStart',
            'recordEnd',
            'availableDisplayModes',
            'editable'
        ]);
        $calendarControlRight = [];
        foreach ($this->availableDisplayModes as $modeKey) {
            if(array_key_exists($modeKey, $this->displayModeDictionary)){
                $calendarControlRight[] = $this->displayModeDictionary[$modeKey];
            }
        }
        $this->availableDisplayModes = implode(",", $calendarControlRight);

    }

    /**
     * @inheritDoc
     */
    protected function loadAssets()
    {
        // $this->addCss('css/fullcalendar.css', '4.0.0-alpha.4');
        $this->addCss(['css/fullcalendar.css' ,'less/calendar.less'], 'core');
        $this->addJs('js/fullcalendar.js', '4.0.0-alpha.4');
        $this->addJs('js/october.calendar.js', 'core');
    }

    public function prepareVars()
    {
        $this->vars['cssClasses'] = implode(' ', $this->cssClasses);

    }

    public function render($options = null)
    {
        $this->prepareVars();
        $extraVars = [];
        return $this->makePartial(static::PARTIAL_FILE, $extraVars);
    }

    protected function isColumnRelated($column, $multi = false)
    {
        if (!isset($column->relation) || $this->isColumnPivot($column)) {
            return false;
        }

        if (!$this->model->hasRelation($column->relation)) {
            throw new ApplicationException(Lang::get(
                'backend::lang.model.missing_relation',
                ['class'=>get_class($this->model), 'relation'=>$column->relation]
            ));
        }

        if (!$multi) {
            return true;
        }

        $relationType = $this->model->getRelationType($column->relation);

        return in_array($relationType, [
            'hasMany',
            'belongsToMany',
            'morphToMany',
            'morphedByMany',
            'morphMany',
            'attachMany',
            'hasManyThrough'
        ]);
    }

    /**
     * Returns a collection of columns which can be searched.
     * @return array
     */
    protected function getSearchableColumns()
    {
        if ($this->searchableColumns != null) return $this->searchableColumns;
        $searchable = [];

        foreach ($columns as $column) {
            if (!$column->searchable) {
                continue;
            }

            $searchable[] = $column;
        }
        $this->searchableColumns = $searchable;
        return $searchable;
    }

    protected function getVisibleColumns()
    {
        if ($this->visiableColumns != null) return $this->visiableColumns;
        $visiableColumns = [$this->recordTitle, $this->recordStart, $this->recordEnd];
        $this->visiableColumns = [];
        foreach ($this->columns as $name => $column){
            if(array_key_exists($name , $visiableColumns )){
                $this->visiableColumns[$name] = $column;
            }
        }
        return $this->visiableColumns;
    }

    /**
     * Replaces the @ symbol with a table name in a model
     * @param  string $sql
     * @param  string $table
     * @return string
     */
    protected function parseTableName($sql, $table)
    {
        return str_replace('@', $table.'.', $sql);
    }

    /**
     * Applies the search constraint to a query.
     */
    protected function applySearchToQuery($query, $columns, $boolean = 'and')
    {
        $term = $this->searchTerm;

        if ($scopeMethod = $this->searchScope) {
            $searchMethod = $boolean == 'and' ? 'where' : 'orWhere';
            $query->$searchMethod(function ($q) use ($term, $columns, $scopeMethod) {
                $q->$scopeMethod($term, $columns);
            });
        }
        else {
            $searchMethod = $boolean == 'and' ? 'searchWhere' : 'orSearchWhere';
            $query->$searchMethod($term, $columns, $this->searchMode);
        }
    }

    /**
     * Applies any filters to the model.
     */
    public function prepareQuery()
    {
        $query = $this->model->newQuery();
        $primaryTable = $this->model->getTable();
        $selects = [$primaryTable.'.*'];
        $joins = [];
        $withs = [];

        $this->fireSystemEvent('backend.calendar.extendQueryBefore', [$query]);

        /*
         * Prepare searchable column names
         */
        $primarySearchable = [];
        $relationSearchable = [];

        $columnsToSearch = [];
        if (!empty($this->searchTerm) && ($searchableColumns = $this->getSearchableColumns())) {
            foreach ($searchableColumns as $column) {
                /*
                 * Related
                 */
                if ($this->isColumnRelated($column)) {
                    $table = $this->model->makeRelation($column->relation)->getTable();
                    $columnName = isset($column->sqlSelect)
                        ? DbDongle::raw($this->parseTableName($column->sqlSelect, $table))
                        : $table . '.' . $column->valueFrom;

                    $relationSearchable[$column->relation][] = $columnName;
                }
                /*
                 * Primary
                 */
                else {
                    $columnName = isset($column->sqlSelect)
                        ? DbDongle::raw($this->parseTableName($column->sqlSelect, $primaryTable))
                        : DbDongle::cast(Db::getTablePrefix() . $primaryTable . '.' . $column->columnName, 'TEXT');

                    $primarySearchable[] = $columnName;
                }
            }
        }
        $visiableColumns = $this->getVisibleColumns();
        foreach ($visiableColumns as $column) {

            // If useRelationCount is enabled, eager load the count of the relation into $relation_count
            if ($column->relation && @$column->config['useRelationCount']) {
                $query->withCount($column->relation);
            }
            if (!$this->isColumnRelated($column) || (!isset($column->sqlSelect) && !isset($column->valueFrom))) {
                continue;
            }
            if (isset($column->valueFrom)) {
                $withs[] = $column->relation;
            }
            $joins[] = $column->relation;
        }

        /*
         * Add eager loads to the query
         */
        if ($withs) {
            $query->with(array_unique($withs));
        }
        /*
         * Apply search term
         */
        $query->where(function ($innerQuery) use ($primarySearchable, $relationSearchable, $joins) {

            /*
             * Search primary columns
             */
            if (count($primarySearchable) > 0) {
                $this->applySearchToQuery($innerQuery, $primarySearchable, 'or');
            }

            /*
             * Search relation columns
             */
            if ($joins) {
                foreach (array_unique($joins) as $join) {
                    /*
                     * Apply a supplied search term for relation columns and
                     * constrain the query only if there is something to search for
                     */
                    $columnsToSearch = array_get($relationSearchable, $join, []);

                    if (count($columnsToSearch) > 0) {
                        $innerQuery->orWhereHas($join, function ($_query) use ($columnsToSearch) {
                            $this->applySearchToQuery($_query, $columnsToSearch);
                        });
                    }
                }
            }
        });

        /*
         * Custom select queries
         */
        foreach ($visiableColumns as $column) {
            if (!isset($column->sqlSelect)) {
                continue;
            }

            $alias = $query->getQuery()->getGrammar()->wrap($column->columnName);

            /*
             * Relation column
             */
            if (isset($column->relation)) {

                // @todo Find a way...
                $relationType = $this->model->getRelationType($column->relation);
                if ($relationType == 'morphTo') {
                    throw new ApplicationException('The relationship morphTo is not supported for list columns.');
                }

                $table =  $this->model->makeRelation($column->relation)->getTable();
                $sqlSelect = $this->parseTableName($column->sqlSelect, $table);

                /*
                 * Manipulate a count query for the sub query
                 */
                $relationObj = $this->model->{$column->relation}();
                $countQuery = $relationObj->getRelationExistenceQuery($relationObj->getRelated()->newQueryWithoutScopes(), $query);

                $joinSql = $this->isColumnRelated($column, true)
                    ? DbDongle::raw("group_concat(" . $sqlSelect . " separator ', ')")
                    : DbDongle::raw($sqlSelect);

                $joinSql = $countQuery->select($joinSql)->toSql();

                $selects[] = Db::raw("(".$joinSql.") as ".$alias);
            }
            /*
             * Primary column
             */
            else {
                $sqlSelect = $this->parseTableName($column->sqlSelect, $primaryTable);
                $selects[] = DbDongle::raw($sqlSelect . ' as '. $alias);
            }
        }

        /*
         * Apply filters
         */
        foreach ($this->filterCallbacks as $callback) {
            $callback($query);
        }
        /*
         * Add custom selects
         */
        $query->addSelect($selects);

        if ($event = $this->fireSystemEvent('backend.calendar.extendQuery', [$query])) {
            return $event;
        }
        return $query;
    }

    public function onFetchEvents()
    {
        // $records = $this->config->modelClass::select($this->recordId, $this->recordTitle, $this->recordStart, $this->recordEnd)->get();
        $query = $this->prepareQuery();
        $records = $query->get();
        $list = [];
        foreach ($records as $record) {
            $id = $record->{$this->recordId};
            $eventData = new EventData([
                'id' => $id,
                'url' => str_replace(':id', $id, $this->recordUrl),
                'title' => $record->{$this->recordTitle},
                'start' => $record->{$this->recordStart},
                'end' => $record->{$this->recordEnd}
            ]);
            $list[] = $eventData->toArray();
        }
        traceLog($list);

        return Response::json([
            'events' => $list
        ]);
    }

    // search

    /**
     * Applies a search term to the list results, searching will disable tree
     * view if a value is supplied.
     * @param string $term
     */
    public function setSearchTerm($term)
    {
        $this->searchTerm = $term;
    }

    /**
     * Applies a search options to the list search.
     * @param array $options
     */
    public function setSearchOptions($options = [])
    {
        extract(array_merge([
            'mode' => null,
            'scope' => null
        ], $options));

        $this->searchMode = $mode;
        $this->searchScope = $scope;
    }
    /**
     * Event handler for changing the filter
     */
    public function onFilter()
    {
        // $this->currentPageNumber = 1;
        return $this->onRefresh();
    }
    //
    // Filtering
    //

    public function addFilter(callable $filter)
    {
        $this->filterCallbacks[] = $filter;
    }

}
