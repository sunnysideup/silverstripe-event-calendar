<?php

namespace UncleCheese\EventCalendar\Tasks;

use Carbon\Carbon;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Versioned\Versioned;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use UncleCheese\EventCalendar\Models\CachedCalendarEntry;
use UncleCheese\EventCalendar\Pages\Calendar;

/**
 * Generates a given number of years of events and populates a readonly table with all the event information.
 * Useful when using recurring events or multiple calendars. Only used if Calendar.caching_enabled is on.
 *
 * Run this regularly (e.g. hourly through cron) so the cache stays up to date.
 */
class CachedCalendarBuildTask extends BuildTask
{
    protected static string $commandName = 'cache-event-calendars';

    protected string $title = 'Cache the Event Calendars';

    protected static string $description = 'Generates a given number of years of events and populates a readonly '
        . 'table with all the event information. Useful when using recurring events or multiple calendars.';

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        Environment::increaseTimeLimitTo(300);

        Versioned::withVersionedMode(function () use ($output) {
            Versioned::set_stage(Versioned::LIVE);
            $this->cacheAll($output);
        });

        return Command::SUCCESS;
    }

    protected function cacheAll(PolyOutput $output): void
    {
        $futureYears = max(1, (int) Calendar::config()->cache_future_years);
        $table = DataObject::getSchema()->tableName(CachedCalendarEntry::class);
        DB::query('DELETE FROM "' . $table . '"');

        foreach (Calendar::get() as $calendar) {
            $output->writeln('Caching calendar ' . OutputFormatter::escape((string) $calendar->Title));
            foreach ($calendar->getAllCalendars() as $c) {
                foreach ($c->AllChildren() as $event) {
                    if ($event->Recursion) {
                        $this->cacheRecurringEvent($output, $calendar, $event, $futureYears);
                        continue;
                    }
                    foreach ($event->DateTimes() as $dt) {
                        CachedCalendarEntry::create_from_datetime($dt, $calendar)->write();
                    }
                }
                foreach ($c->Announcements() as $announcement) {
                    CachedCalendarEntry::create_from_announcement($announcement, $calendar)->write();
                }
            }
        }
    }

    protected function cacheRecurringEvent(PolyOutput $output, Calendar $calendar, $event, int $futureYears): void
    {
        $dt = $event->DateTimes()->first();
        if (!$dt || !$dt->StartDate) {
            return;
        }

        $maxEnd = Carbon::now()->addYears($futureYears)->startOfDay();
        $end = $dt->EndDate ? Carbon::parse($dt->EndDate)->startOfDay() : $maxEnd;
        if ($end > $maxEnd) {
            $end = $maxEnd;
        }

        $reader = $event->getRecursionReader();
        $counter = Carbon::parse($dt->StartDate)->startOfDay();
        $created = 0;
        while ($counter <= $end) {
            if ($reader->recursionHappensOn($counter->getTimestamp())) {
                $dt->StartDate = $counter->toDateString();
                $dt->EndDate = $counter->toDateString();
                CachedCalendarEntry::create_from_datetime($dt, $calendar)->write();
                $created++;
            }
            $counter->addDay()->startOfDay();
        }
        $output->writeln(sprintf(
            '%d events created for recurring event %s',
            $created,
            OutputFormatter::escape((string) $event->Title)
        ));
    }
}
