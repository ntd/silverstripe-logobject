<?php

namespace eNTiDi\LogObject;

use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\Connect\TableBuilder;
use SilverStripe\ORM\DB;
use SilverStripe\PolyExecution\PolyOutput;

/**
 * Extends `DbBuild` to also build `LogObject` subclasses tables, which
 * are not `DataObject` and thus are ignored by the default `dev/build`
 */
class DbBuildExtension extends Extension
{
    protected function onAfterBuild($output, $populate, $is_test)
    {
        $classes = ClassInfo::subclassesFor(LogObject::class);
        array_shift($classes);
        if (empty($classes)) {
            return;
        }

        $output->writeln(['<options=bold>Creating log tables</>', '']);
        $output->startList(PolyOutput::LIST_UNORDERED);
        foreach ($classes as $class) {
            $output->writeListItem($class::tableName());
        }
        $output->stopList();

        // TableBuilder::buildTables() cannot print the table names
        // itself (DataObjectSchema::tableName() returns null for
        // non-DataObjects) and counting the records would require a
        // slow full table scan on huge log tables, so it is invoked
        // in quiet mode
        TableBuilder::singleton()->buildTables(
            DB::get_schema(),
            $classes,
            [],
            true,
            $is_test,
            false
        );

        $output->writeln(['<options=bold>Log tables created!</>', '']);
    }
}
