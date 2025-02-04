<?php

namespace Sunnysidep\RecentlyChanged;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataObject;
use SilverStripe\Core\ClassInfo;
use SilverStripe\ORM\DB;
use SilverStripe\Security\LoginAttempt;
use SilverStripe\Security\RememberLoginHash;
use SilverStripe\SessionManager\Models\LoginSession;

class ChangedDataObjectsTask extends BuildTask
{
    protected $title = 'Changed DataObjects';
    protected $description = 'Lists DataObjects and tables with LastEdited changed since a computed date based on days back.';

    private static $segment = 'changed-data-objects';

    private static $skip_classes = [
        RememberLoginHash::class,
        LoginSession::class,
        LoginAttempt::class,
    ];
    private static $skip_tables = [
        'RememberLoginHash',
        'LoginSession',
        'LoginAttempt',
    ];

    public function run($request)
    {
        $daysBackParam = $request->getVar('daysBack') ?: 30;
        $daysBack = (float)$daysBackParam;

        echo $this->getInputForm($daysBack);

        $timestamp = time() - ($daysBack * 86400);
        $dateBack = date('Y-m-d H:i:s', $timestamp);

        DB::alteration_message('<h2>Using date: ' . $dateBack . "</h2>");
        $objectTables = [];
        $classes = ClassInfo::subclassesFor(DataObject::class);
        $classesToCheck = [];
        foreach ($classes as $key => $className) {
            $objectTables[$className] = $this->getTableForClass($className);
            if ($className === DataObject::class) {
                continue;
            }
            if (in_array($className, $this->config()->get('skip_classes'))) {
                continue;
            }
            $classesToCheck[] = $this->getBaseClass($className);
        }
        $classesToCheck = array_unique($classesToCheck);

        foreach ($classesToCheck as $className) {
            $results = $className::get()->filter('LastEdited:GreaterThan', $dateBack);
            if ($results->exists()) {
                DB::alteration_message('<strong>DataObjects of class ' . $className . ' changed since ' . $dateBack . '</strong>');
                foreach ($results as $record) {
                    $title = $record->getTitle();
                    $cmsEditLink = null;
                    $link = null;
                    if ($record->hasMethod('CMSEditLink')) {
                        $cmsEditLink = $record->CMSEditLink();
                    }
                    if ($record->hasMethod('Link')) {
                        $link = $record->CMSEditLink();
                    }
                    $cmsEditLink = $cmsEditLink ? '<a href="/' . $cmsEditLink . '">✏️</a>' : '<del>✏️</del>';
                    $link = $link ? '<a href="/' . $link . '">🔗</a>' : '<del>🔗</del>';
                    DB::alteration_message(
                        ' -- ' . $cmsEditLink . ' ' .
                            $link . ' ' .
                            '<strong>ID:</strong> ' . $record->ID . ', ' .
                            '<strong>Title:</strong> ' . $title . ', ' .
                            '<strong>LastEdited:</strong> ' . $record->LastEdited
                    );
                }
                DB::alteration_message("---");
            }
        }

        $allTables = [];
        $rows = DB::query('SHOW TABLES');
        $objectTables += $this->getBaseTables();
        foreach ($rows as $row) {
            $tableName = reset($row);
            if (!in_array($tableName, $this->config()->get('skip_tables'))) {
                if (!in_array($tableName, $objectTables)) {
                    $allTables[] = $tableName;
                }
            }
        }
        // Determine additional tables not linked to a DataObject
        $additionalTablesNoLastEdited = [];
        $additionalTablesWithLastEdited = [];
        foreach ($allTables as $tableName) {
            if (in_array($tableName, $objectTables)) {
                continue;
            }
            $colQuery = DB::query("SHOW COLUMNS FROM `$tableName` LIKE 'LastEdited'");
            if ($colQuery->numRecords() > 0) {
                $additionalTablesWithLastEdited[] = $tableName;
            } else {
                $additionalTablesNoLastEdited[] = $tableName;
            }
        }

        // For each additional table that has a LastEdited field, query for records updated since $dateBack.
        foreach ($additionalTablesWithLastEdited as $tableName) {
            $recordsQuery = DB::query("SELECT * FROM `$tableName` WHERE LastEdited > '$dateBack'");
            $recordCount = $recordsQuery->numRecords();
            if ($recordCount > 0) {
                DB::alteration_message('<strong>Table ' . $tableName . ' has ' . $recordCount . ' record(s) updated since ' . $dateBack . '</strong>');
                foreach ($recordsQuery as $row) {
                    $recordId = isset($row['ID']) ? $row['ID'] : '(no ID)';
                    DB::alteration_message(' -- Record ID: ' . $recordId . ', LastEdited: ' . $row['LastEdited']);
                }
            }
        }

        // Optionally, list the additional tables without a LastEdited field.
        DB::alteration_message('<h2>Additional tables WITHOUT a LastEdited field:</h2>');
        foreach ($additionalTablesNoLastEdited as $tableName) {
            DB::alteration_message(' - ' . $tableName);
        }
    }

    protected function getInputForm(float $defaultDaysBack): string
    {
        $html = '<form method=\'get\' action=\'\'>';
        $html .= '<label for=\'daysBack\'>Enter number of days back (e.g. 0.5, 1, 30): </label>';
        $html .= '<input type=\'number\' name=\'daysBack\' id=\'daysBack\' value=\'' . $defaultDaysBack . '\'>';
        $html .= '<input type=\'submit\' value=\'Submit\'>';
        $html .= '</form>';
        return $html;
    }

    protected function getTableForClass(string $className): string
    {
        $schema = DataObject::getSchema();
        return (string) $schema->tableName($className);
    }

    protected function getBaseTables(): array
    {
        $schema = DataObject::getSchema();
        $list = $schema->getTableNames();
        foreach ($list as $key => $tableName) {
            $list[$key . '_versions'] = $tableName . '_versions';
            $list[$key . '_Versions'] = $tableName . '_Versions';
            $list[$key . '_Live'] = $tableName . '_Live';
        }
        return $list;
        // $tables = [];

        // // Start with the current object's class.
        // $currentClass = get_class($dataObject);

        // // Traverse up the inheritance chain until we reach DataObject itself.
        // while ($currentClass && ($currentClass === DataObject::class || is_subclass_of($currentClass, DataObject::class))) {
        //     // Record the table name for this class.
        //     $tables[$currentClass] = $schema->tableName($currentClass);

        //     // If we've reached the base DataObject class, stop.
        //     if ($currentClass === DataObject::class) {
        //         break;
        //     }

        //     // Move to the parent class.
        //     $currentClass = get_parent_class($currentClass);
        // }

        // return $tables;
    }

    protected function getBaseClass(string $className): string
    {

        // Start with the current object's class.
        $currentClass = $className;
        $return = $currentClass;

        // Traverse up the inheritance chain until we reach DataObject itself.
        while ($currentClass && is_subclass_of($currentClass, DataObject::class)) {
            // If we've reached the base DataObject class, stop.
            if ($currentClass === DataObject::class) {
                break;
            }
            // Record the table name for this class.
            $return = $currentClass;

            // Move to the parent class.
            $currentClass = get_parent_class($currentClass);
        }

        return $return;
    }
}
