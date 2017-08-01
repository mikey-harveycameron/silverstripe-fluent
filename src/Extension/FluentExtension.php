<?php

namespace TractorCow\Fluent\Extension;

use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataQuery;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\Queries\SQLSelect;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;

/**
 * Basic fluent extension
 */
class FluentExtension extends DataExtension
{
    const SUFFIX = 'Localised';

    private static $db_for_localised_table = [
        'ID' => 'PrimaryKey',
        'RecordID' => 'Int',
        'Locale' => 'Varchar(10)',
    ];

    private static $indexes_for_localised_table = [
        'Fluent_Record' => [
            'type' => 'unique',
            'columns' => [
                'RecordID',
                'Locale',
            ],
        ],
    ];

    /**
     * Filter whitelist of fields to localise
     *
     * @config
     * @var array
     */
    private static $field_include = [];

    /**
     * Filter blacklist of fields to localise
     *
     * @config
     * @var array
     */
    private static $field_exclude = [
        'ID',
        'ClassName',
        'Theme',
        'Priority',
    ];

    /**
     * Filter whitelist of field types to localise
     *
     * @config
     * @var
     */
    private static $data_include = [
        'Text',
        'Varchar',
        'HTMLText',
        'HTMLVarchar',
    ];

    /**
     * Filter blacklist of field types to localise
     *
     * @config
     * @var array
     */
    private static $data_exclude = [];

    /**
     * Cache of localised fields for this model
     */
    protected $localisedFields = [];

    /**
     * Get list of fields that are localised
     *
     * @param string $class Class to get fields for (if parent)
     * @return array
     */
    public function getLocalisedFields($class = null)
    {
        if (!$class) {
            $class = get_class($this->owner);
        }
        if (isset($this->localisedFields[$class])) {
            return $this->localisedFields[$class];
        }

        // List of DB fields
        $fields = DataObject::getSchema()->databaseFields($class, false);
        $filter = Config::inst()->get($class, 'translate', Config::UNINHERITED);
        if ($filter === 'none' || empty($fields)) {
            return $this->localisedFields[$class] = [];
        }

        // Data and field filters
        $fieldsInclude = Config::inst()->get($class, 'field_include');
        $fieldsExclude = Config::inst()->get($class, 'field_exclude');
        $dataInclude = Config::inst()->get($class, 'data_include');
        $dataExclude = Config::inst()->get($class, 'data_exclude');

        // filter out DB
        foreach ($fields as $field => $type) {
            // If given an explicit field name filter, then remove non-presented fields
            if ($filter) {
                if (!in_array($field, $filter)) {
                    unset($fields[$field]);
                }
                continue;
            }

            // Without a name filter then check against each filter type
            if (($fieldsInclude && !$this->anyMatch($field, $fieldsInclude))
                || ($fieldsExclude && $this->anyMatch($field, $fieldsExclude))
                || ($dataInclude && !$this->anyMatch($type, $dataInclude))
                || ($dataExclude && $this->anyMatch($type, $dataExclude))
            ) {
                unset($fields[$field]);
            }
        }

        return $this->localisedFields[$class] = $fields;
    }

    /**
     * Get all database tables in the class ancestry and their respective
     * translatable fields
     *
     * @return array
     */
    protected function getLocalisedTables()
    {
        $includedTables = array();
        foreach ($this->owner->getClassAncestry() as $class) {
            // Skip classes without tables
            if (!DataObject::getSchema()->classHasTable($class)) {
                continue;
            }

            // Check translated fields for this class
            $translatedFields = $this->getLocalisedFields($class);
            if (empty($translatedFields)) {
                continue;
            }

            // Mark this table as translatable
            $table = DataObject::getSchema()->tableName($class);
            $includedTables[$table] = array_keys($translatedFields);
        }
        return $includedTables;
    }

    /**
     * Helper function to check if the value given is present in any of the patterns.
     * This function is case sensitive by default.
     *
     * @param string $value A string value to check against, potentially with parameters (E.g. 'Varchar(1023)')
     * @param array $patterns A list of strings, some of which may be regular expressions
     * @return boolean True if this $value is present in any of the $patterns
     */
    protected function anyMatch($value, $patterns)
    {
        // Test both explicit value, as well as the value stripped of any trailing parameters
        $valueBase = preg_replace('/\(.*/', '', $value);
        foreach ($patterns as $pattern) {
            if (strpos($pattern, '/') === 0) {
                // Assume value prefaced with '/' are regexp
                if (preg_match($pattern, $value) || preg_match($pattern, $valueBase)) {
                    return true;
                }
            } else {
                // Assume simple string comparison otherwise
                if ($pattern === $value || $pattern === $valueBase) {
                    return true;
                }
            }
        }
        return false;
    }

    public function augmentDatabase()
    {
        // Build _Localisation table
        $class = get_class($this->owner);
        $schema = DataObject::getSchema();

        // Don't require table if no fields
        $localisedFields = $this->getLocalisedFields($class);
        $localisedTable = $schema->tableName($class) . '_' . self::SUFFIX;
        if (empty($localisedFields)) {
            DB::dont_require_table($localisedTable);
            return;
        }

        // Merge fields and indexes
        $fields = array_merge(
            $this->owner->config()->get('db_for_localised_table'),
            $localisedFields
        );
        $indexes = $this->owner->config()->get('indexes_for_localised_table');
        DB::require_table($localisedTable, $fields, $indexes, false);
    }

    public function augmentSQL(SQLSelect $query, DataQuery $dataQuery = null)
    {
        $localeCode = $dataQuery->getQueryParam('Fluent.Locale') ?: FluentState::singleton()->getLocale();
        if (!$localeCode) {
            return;
        }

        // Get locale and translation zone to use
        $default = Locale::getDefault();
        $locale = Locale::getByLocale($localeCode);

        // Only rewrite if we have a locale and a default, and they don't match
        if (!$default || !$locale || $default->Locale === $locale->Locale) {
            return;
        }

        // Join all tables on the given locale code
        $tables = $this->getLocalisedTables();
        foreach ($tables as $table => $fields) {
            $tableLocalised = $table . '_' . self::SUFFIX;

            // Join all items in ancestory
            $joinLocale = $locale;
            while ($joinLocale) {
                $joinAlias = $tableLocalised . '_' . $joinLocale->Locale;
                $query->addLeftJoin(
                    $tableLocalised,
                    " \"{$table}\".\"ID\" = \"{$joinAlias}\".\"RecordID\" AND \"{$joinAlias}\".\"Locale\" = ?",
                    $joinAlias,
                    20,
                    [ $joinLocale->Locale ]
                );
                // Join next parent
                $joinLocale = $joinLocale->getParent();
            }
        }
    }
}
