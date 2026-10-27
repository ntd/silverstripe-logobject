<?php

namespace eNTiDi\LogObject;

use eNTiDi\LogObject\LogObject;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Extension;
use SilverStripe\Dev\Command\DbBuild;
use SilverStripe\ORM\Connect\TableBuilder;
use SilverStripe\ORM\DB;
use SilverStripe\PolyExecution\PolyOutput;

class DbBuildExtension extends Extension
{
    protected function onAfterBuild($output, $populate, $is_test)
    {
        $classes = ClassInfo::subclassesFor(LogObject::class);
        array_shift($classes);
        if (empty($classes)) {
            return;
        }

        // Similar to what already done in DbBuild for DataObjects
        $output->writeln(['<options=bold>Creating log tables</>', '']);
        $output->startList(PolyOutput::LIST_UNORDERED);
        TableBuilder::singleton()->buildTables(
            DB::get_schema(),
            $classes,
            [],
            $output->isQuiet(),
            $is_test,
            (bool) DbBuild::config()->get('show_record_counts')
        );
        $output->stopList();

        $output->writeln(['<options=bold>Log tables created!</>', '']);

        foreach ($classes as $class) {
            LogObject::singleton($class)->onAfterBuild();
        }

        // XXX: no idea what this does
        ClassInfo::reset_db_cache();
    }
}
