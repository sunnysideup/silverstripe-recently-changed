<?php

namespace Sunnysidep\RecentlyChanged;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataObject;
use SilverStripe\Core\ClassInfo;

class ChangedDataObjectsTask extends BuildTask
{
    protected string $title = 'Changed DataObjects Task';
    protected string $description = 'Lists DataObjects and tables with LastEdited changed since a computed date based on days back.';

    private static $segment = 'changed-data-objects';

    public function run(HTTPRequest $request): void
    {
        $daysBackParam = $request->getVar('daysBack')?:30;
        $daysBack = (float)$daysBackParam;

        echo $this->getInputForm($daysBack);

        $timestamp = time() - ($daysBack * 86400);
        $dateBack = date('Y-m-d H:i:s', $timestamp);

        DB::alteration_message( 'Using date: ' . $dateBack . "\n");

        $classes = ClassInfo::subclassesFor(DataObject::class);
        foreach ($classes as $className) {
            if ($className === DataObject::class) {
                continue;
            }
            $results = $className::get()->filter('LastEdited:GreaterThan', $dateBack);
            if ($results->exists()) {
                DB::alteration_message( 'DataObjects of class ' . $className . ' changed since ' . $dateBack . "\n";
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
                    $cmsEditLink ? 'Link: <a href="' . $cmsEditLink . '">✏️</a>' : '<del>✏️</del>';
                    $link ? 'Link: <a href="' . $link . '">🔗</a>' : '<del>🔗</del>';
                    DB::alteration_message(
                        ' -- '.$cmsEditLink . ' '.
                        $link . ' '.
                        'ID: ' . $title . ', '.
                        'Title: ' . $title . ', '.
                        'LastEdited: ' . $record->LastEdited
                    );
                }
                DB::alteration_message("---");
            }
        }

        $schema = DataObject::getSchema();
        foreach ($classes as $className) {
            if ($className === DataObject::class) {
                continue;
            }
            try {
                $fieldSpec = $schema->fieldSpec($className, 'LastEdited');
                if ($fieldSpec) {
                    $tableName = $schema->tableName($className);
                    DB::alteration_message( 'Table ' . $tableName . ' (from ' . $className . ') has a LastEdited column.');
                }
            } catch (\Exception $ex) {
                // Ignore missing field
            }
        }
    }

    protected function getInputForm(float $defaultDaysBack): string
    {
        $html = '<form method=\'get\' action=\'\'>';
        $html .= '<label for=\'daysBack\'>Enter number of days back (e.g. 0.5, 1, 30): </label>';
        $html .= '<input type=\'number\' step=\'0.1\' name=\'daysBack\' id=\'daysBack\' value=\'' . $defaultDaysBack . '\'>';
        $html .= '<input type=\'submit\' value=\'Submit\'>';
        $html .= '</form>';
        return $html;
    }
}
