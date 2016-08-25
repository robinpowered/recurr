<?php

/*
 * Copyright 2013 Shaun Simmons
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * Based on:
 * rrule.js - Library for working with recurrence rules for calendar dates.
 * Copyright 2010, Jakub Roztocil and Lars Schoning
 * https://github.com/jkbr/rrule/blob/master/LICENCE
 */

namespace Recurr\Transformer;

use DateTime;
use DateTimeZone;
use Generator;
use Recurr\DateUtil;
use Recurr\Exception\MissingData;
use Recurr\Frequency;
use Recurr\Rule;
use Recurr\Time;
use Recurr\Weekday;

/**
 * Expands instances for a given RRULE.
 *
 * @package Recurr
 */
class InstanceGenerator
{
    /**
     * Some versions of PHP are affected by a bug where
     * DateTime::createFromFormat('z Y', ...) does not account for leap years.
     *
     * @var bool
     */
    protected $leapBug = false;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->leapBug = DateUtil::hasLeapYearBug();
    }

    /**
     * Returns a generator that yields instances for the given rules and dates.
     *
     * @param DateTimeZone $timeZone The time zone to expand instances within.
     * @param Rule[] $rrules A collection of iCal RRULE values to expand by.
     * @param DateTime[] $rdates A collection of iCal RDATE values to include in the expansion.
     * @param Rule[] $exrules A collection of iCal EXRULE values to expand and exclude.
     * @param DateTime[] $exdates A collection of iCal EXDATE values to exclude in the expansion. (NOTE: EXDATE was
     *   introduced in RFC2445 and deprecated in RFC5545. It is included here for legacy support because it is commonly
     *   supported by popular cloud-based calendar systems like Google Calendar.
     * @param bool $ignoreCounts Ignores the `COUNT` constraint of all rules given. This is useful if the `$seed`
     *   represents a date in the middle of the series and using `COUNT` would be cause for an inaccurate end date.
     * @param int|null $iterationLimit An iteration limit that, when provided, will serve as a fail-safe
     *   that stops the generator when the limit is reached to prevent an infinitely large iterator.
     * @return Generator A generator that yields {@link DateTime} values representing instance start time.
     * @throws MissingData
     */
    public function generate(
        DateTimeZone $timeZone,
        array $rrules = [],
        array $rdates = [],
        array $exrules = [],
        array $exdates = [],
        $ignoreCounts = false,
        $iterationLimit = null
    ) {
        if (empty($rrules) && empty($rdates) && empty($exrules) && empty($exdates)) {
            return; // Empty generator.
        }

        // An array to hold all the generators for dates that we'll include.
        $rGenerators = [];

        // An array to hold all the generators for dates that we'll exclude.
        $exGenerators = [];

        // Add a generator for the RDATE collection
        $rGenerators[] = $this->generateFromDates($rdates, $timeZone);

        // Add a generator for each RRULE
        foreach ($rrules as $rrule) {
            $rGenerators[] = $this->generateFromRule(
                $rrule,
                $timeZone,
                $ignoreCounts
            );
        }

        // Add a generator for the EXDATE collection
        $exGenerators[] = $this->generateFromDates($exdates, $timeZone);

        // Add a generator for each EXRULE
        foreach ($exrules as $exrule) {
            $exGenerators[] = $this->generateFromRule(
                $exrule,
                $timeZone,
                $ignoreCounts
            );
        }

        $iterations = 0;

        do {
            /** @var Generator $generator */

            // Holds the next date provided by each `$r_generators`

            $rDates = [];

            $rGeneratorsToKeep = [];

            foreach ($rGenerators as $key => $generator) {
                if (!$generator->valid()) {
                    // This generator has reached it's end... Skip it and don't keep it.
                    continue;
                }

                $rDates[] = $generator->current();
                $rGeneratorsToKeep[] = $generator;
            }

            $rGenerators = $rGeneratorsToKeep;

            if (empty($rDates)) {
                // No next date could be found, so we're done here.
                break;
            }

            // Get the lowest of all the `$r_dates` This is the "next" instance date for the series, so long as
            // it's not also found in with the EXDATES or the EXRULEs.
            $minRDate = $this->minDate($rDates);

            $dateIsExcluded = false;

            $exGeneratorsToKeep = [];

            foreach ($exGenerators as $generator) {
                if (!$generator->valid()) {
                    // This generator has reached it's end... Skip it and don't keep it.
                    continue;
                }

                // Iterate through all exdates until we reach the `$min_r_date`
                while ($generator->valid() && $generator->current()->getTimestamp() < $minRDate->getTimestamp()) {
                    $generator->next();
                }

                // If the current EXDATE matches the current `$min_r_date`, then the date should be excluded from the
                // series.
                if ($generator->valid() && $generator->current()->getTimestamp() === $minRDate->getTimestamp()) {
                    $dateIsExcluded = true;
                }

                $exGeneratorsToKeep[] = $generator;
            }

            $exGenerators = $exGeneratorsToKeep;

            if (!$dateIsExcluded) {
                $iterations++;
                yield $minRDate;
            }

            foreach ($rGenerators as $generator) {
                // Advance all the `$r_generators` that yielded the previous result.
                if ($generator->current()->getTimestamp() === $minRDate->getTimestamp()) {
                    $generator->next();
                }
            }
            // Keep looping so long as the `$r_generators` are yielding dates and we're under the iteration limit.
        } while (!empty($rGenerators) && (null === $iterationLimit || $iterations < $iterationLimit));

    }

    /**
     * Returns a generator that provides a given array of dates, copied with the given time zone.
     *
     * @param DateTime[] $datetimes The dates to be yielded by the generator.
     * @param DateTimeZone $timeZone The timezone context to give all the yielded dates.
     * @return Generator A generator that yields {@link DateTime} values representing instance start time.
     */
    private function generateFromDates(array $datetimes, DateTimeZone $timeZone)
    {
        $compare = function (DateTime $datetime1, DateTime $datetime2) {
            return ($datetime1->getTimestamp() - $datetime2->getTimestamp());
        };

        usort($datetimes, $compare);

        foreach ($datetimes as $date) {
            $result = clone $date;
            $result->setTimezone($timeZone);
            $result->getTimestamp(); // Fixes PHP timezone bug.

            yield $result;
        }
    }

    /**
     * Returns a generator that provides instance dates based on the given time zone.
     *
     * @param Rule $rule The iCal RRULE or EXRULE value.
     * @param DateTimeZone $timeZone The timezone context to give all the yielded dates.
     * @param bool $ignoreCounts Ignores the `COUNT` constraint of all rules given. This is useful if the `$seed`
     *   represents a date in the middle of the series and using `COUNT` would be cause for an inaccurate end date.
     * @return Generator A generator that yields {@link DateTime} values representing instance start time.
     */
    private function generateFromRule(Rule $rule, DateTimeZone $timeZone, $ignoreCounts = false)
    {
        $start = clone $rule->getStartDate();
        $until = $rule->getUntil();

        if (null === $start) {
            $start = new DateTime('now', $timeZone);
        }

        $start->setTimezone($timeZone);
        $start->getTimestamp(); // Fixes PHP timezone bug.

        $startDay          = $start->format('j');

        $dt = clone $start;

        $maxCount = $rule->getCount();

        $freq          = $rule->getFreq();
        $weekStart     = $rule->getWeekStartAsNum();
        $bySecond      = $rule->getBySecond();
        $byMinute      = $rule->getByMinute();
        $byHour        = $rule->getByHour();
        $byMonth       = $rule->getByMonth();
        $byWeekNum     = $rule->getByWeekNumber();
        $byYearDay     = $rule->getByYearDay();
        $byMonthDay    = $rule->getByMonthDay();
        $byMonthDayNeg = array();
        $byWeekDay     = $rule->getByDayTransformedToWeekdays();
        $byWeekDayRel  = array();
        $bySetPos      = $rule->getBySetPosition();

        if (!(!empty($byWeekNum) || !empty($byYearDay) || !empty($byMonthDay) || !empty($byWeekDay))) {
            switch ($freq) {
                case Frequency::YEARLY:
                    if (empty($byMonth)) {
                        $byMonth = array($start->format('n'));
                    }

                    $byMonthDay = array($startDay);
                    break;
                case Frequency::MONTHLY:
                    $byMonthDay = array($startDay);
                    break;
                case Frequency::WEEKLY:
                    $byWeekDay = array(
                        new Weekday(
                            DateUtil::getDayOfWeek($start), null
                        )
                    );
                    break;
            }
        }

        if (is_array($byMonthDay) && count($byMonthDay)) {
            foreach ($byMonthDay as $idx => $day) {
                if ($day < 0) {
                    unset($byMonthDay[$idx]);
                    $byMonthDayNeg[] = $day;
                }
            }
        }

        if (!empty($byWeekDay)) {
            foreach ($byWeekDay as $idx => $day) {
                /** @var $day Weekday */

                if (!empty($day->num)) {
                    $byWeekDayRel[] = $day;
                    unset($byWeekDay[$idx]);
                } else {
                    $byWeekDay[$idx] = $day->weekday;
                }
            }
        }

        if (empty($byYearDay)) {
            $byYearDay = null;
        }

        if (empty($byMonthDay)) {
            $byMonthDay = null;
        }

        if (empty($byMonthDayNeg)) {
            $byMonthDayNeg = null;
        }

        if (empty($byWeekDay)) {
            $byWeekDay = null;
        }

        if (!count($byWeekDayRel)) {
            $byWeekDayRel = null;
        }

        $year   = $dt->format('Y');
        $month  = $dt->format('n');
        $hour   = $dt->format('G');
        $minute = $dt->format('i');
        $second = $dt->format('s');

        $total    = 1;
        $count    = $maxCount;
        $continue = true;
        while ($continue) {
            $dtInfo = DateUtil::getDateInfo($dt);

            $tmp         = DateUtil::getDaySet($rule, $dt, $dtInfo, $start);
            $daySet      = $tmp->set;
            $daySetStart = $tmp->start;
            $daySetEnd   = $tmp->end;
            $wNoMask     = array();
            $wDayMaskRel = array();
            $timeSet     = DateUtil::getTimeSet($rule, $dt);

            if ($freq >= Frequency::HOURLY) {
                if (($freq >= Frequency::HOURLY && !empty($byHour) && !in_array(
                            $hour,
                            $byHour
                        )) || ($freq >= Frequency::MINUTELY && !empty($byMinute) && !in_array(
                            $minute,
                            $byMinute
                        )) || ($freq >= Frequency::SECONDLY && !empty($bySecond) && !in_array($second, $bySecond))
                ) {
                    $timeSet = array();
                } else {
                    switch ($freq) {
                        case Frequency::HOURLY:
                            $timeSet = DateUtil::getTimeSetOfHour($rule, $dt);
                            break;
                        case Frequency::MINUTELY:
                            $timeSet = DateUtil::getTimeSetOfMinute($rule, $dt);
                            break;
                        case Frequency::SECONDLY:
                            $timeSet = DateUtil::getTimeSetOfSecond($dt);
                            break;
                    }
                }
            }

            // Handle byWeekNum
            if (!empty($byWeekNum)) {
                $no1WeekStart = $firstWeekStart = DateUtil::pymod(7 - $dtInfo->dayOfWeekYearDay1 + $weekStart, 7);

                if ($no1WeekStart >= 4) {
                    $no1WeekStart = 0;

                    $wYearLength = $dtInfo->yearLength + DateUtil::pymod(
                            $dtInfo->dayOfWeekYearDay1 - $weekStart,
                            7
                        );
                } else {
                    $wYearLength = $dtInfo->yearLength - $no1WeekStart;
                }

                $div      = floor($wYearLength / 7);
                $mod      = DateUtil::pymod($wYearLength, 7);
                $numWeeks = floor($div + ($mod / 4));

                foreach ($byWeekNum as $weekNum) {
                    if ($weekNum < 0) {
                        $weekNum += $numWeeks + 1;
                    }

                    if (!(0 < $weekNum && $weekNum <= $numWeeks)) {
                        continue;
                    }

                    if ($weekNum > 1) {
                        $offset = $no1WeekStart + ($weekNum - 1) * 7;
                        if ($no1WeekStart != $firstWeekStart) {
                            $offset -= 7 - $firstWeekStart;
                        }
                    } else {
                        $offset = $no1WeekStart;
                    }

                    for ($i = 0; $i < 7; $i++) {
                        $wNoMask[] = $offset;
                        $offset++;
                        if ($dtInfo->wDayMask[$offset] == $weekStart) {
                            break;
                        }
                    }
                }

                // Check week number 1 of next year as well
                if (in_array(1, $byWeekNum)) {
                    $offset = $no1WeekStart + $numWeeks * 7;

                    if ($no1WeekStart != $firstWeekStart) {
                        $offset -= 7 - $firstWeekStart;
                    }

                    // If week starts in next year, we don't care about it.
                    if ($offset < $dtInfo->yearLength) {
                        for ($k = 0; $k < 7; $k++) {
                            $wNoMask[] = $offset;
                            $offset += 1;
                            if ($dtInfo->wDayMask[$offset] == $weekStart) {
                                break;
                            }
                        }
                    }
                }

                if ($no1WeekStart) {
                    // Check last week number of last year as well.
                    // If $no1WeekStart is 0, either the year started on week start,
                    // or week number 1 got days from last year, so there are no
                    // days from last year's last week number in this year.
                    if (!in_array(-1, $byWeekNum)) {
                        $dtTmp = new DateTime();
                        $dtTmp->setDate($year - 1, 1, 1);
                        $lastYearWeekDay      = DateUtil::getDayOfWeek($dtTmp);
                        $lastYearNo1WeekStart = DateUtil::pymod(7 - $lastYearWeekDay + $weekStart, 7);
                        $lastYearLength       = DateUtil::getYearLength($dtTmp);
                        if ($lastYearNo1WeekStart >= 4) {
                            $lastYearNumWeeks     = floor(
                                52 + DateUtil::pymod(
                                    $lastYearLength + DateUtil::pymod(
                                        $lastYearWeekDay - $weekStart,
                                        7
                                    ),
                                    7
                                ) / 4
                            );
                        } else {
                            $lastYearNumWeeks = floor(
                                52 + DateUtil::pymod(
                                    $dtInfo->yearLength - $no1WeekStart,
                                    7
                                ) / 4
                            );
                        }
                    } else {
                        $lastYearNumWeeks = -1;
                    }

                    if (in_array($lastYearNumWeeks, $byWeekNum)) {
                        for ($i = 0; $i < $no1WeekStart; $i++) {
                            $wNoMask[] = $i;
                        }
                    }
                }
            }

            // Handle relative weekdays (e.g. 3rd Friday of month)
            if (!empty($byWeekDayRel)) {
                $ranges = array();

                if (Frequency::YEARLY == $freq) {
                    if (!empty($byMonth)) {
                        foreach ($byMonth as $mo) {
                            $ranges[] = array_slice($dtInfo->mRanges, $mo - 1, 2);
                        }
                    } else {
                        $ranges[] = array(0, $dtInfo->yearLength);
                    }
                } elseif (Frequency::MONTHLY == $freq) {
                    $ranges[] = array_slice($dtInfo->mRanges, $month - 1, 2);
                }

                if (!empty($ranges)) {
                    foreach ($ranges as $range) {
                        $rangeStart = $range[0];
                        $rangeEnd   = $range[1];
                        --$rangeEnd;

                        reset($byWeekDayRel);
                        foreach ($byWeekDayRel as $weekday) {
                            /** @var Weekday $weekday */

                            if ($weekday->num < 0) {
                                $i = $rangeEnd + ($weekday->num + 1) * 7;
                                $i -= DateUtil::pymod(
                                    $dtInfo->wDayMask[$i] - $weekday->weekday,
                                    7
                                );
                            } else {
                                $i = $rangeStart + ($weekday->num - 1) * 7;
                                $i += DateUtil::pymod(
                                    7 - $dtInfo->wDayMask[$i] + $weekday->weekday,
                                    7
                                );
                            }

                            if ($rangeStart <= $i && $i <= $rangeEnd) {
                                $wDayMaskRel[] = $i;
                            }
                        }
                    }
                }
            }

            $numMatched = 0;
            foreach ($daySet as $i => $dayOfYear) {
                $ifByMonth = $byMonth !== null && !in_array(
                        $dtInfo->mMask[$dayOfYear],
                        $byMonth
                    );

                $ifByWeekNum = $byWeekNum !== null && !in_array(
                        $i,
                        $wNoMask
                    );

                $ifByYearDay = $byYearDay !== null && (
                        (
                            $i < $dtInfo->yearLength &&
                            !in_array($i + 1, $byYearDay) &&
                            !in_array(-$dtInfo->yearLength + $i, $byYearDay)
                        ) ||
                        (
                            $i >= $dtInfo->yearLength &&
                            !in_array($i + 1 - $dtInfo->yearLength, $byYearDay) &&
                            !in_array(-$dtInfo->nextYearLength + $i - $dtInfo->yearLength, $byYearDay)
                        )
                    );

                $ifByMonthDay = $byMonthDay !== null && !in_array(
                        $dtInfo->mDayMask[$dayOfYear],
                        $byMonthDay
                    );

                $ifByMonthDayNeg = $byMonthDayNeg !== null && !in_array(
                        $dtInfo->mDayMaskNeg[$dayOfYear],
                        $byMonthDayNeg
                    );

                $ifByDay = $byWeekDay !== null && count($byWeekDay) && !in_array(
                        $dtInfo->wDayMask[$dayOfYear],
                        $byWeekDay
                    );

                $ifWDayMaskRel = $byWeekDayRel !== null && !in_array($dayOfYear, $wDayMaskRel);

                if ($byMonthDay !== null && $byMonthDayNeg !== null) {
                    if ($ifByMonthDay && $ifByMonthDayNeg) {
                        unset($daySet[$i]);
                    }
                } elseif ($ifByMonth || $ifByWeekNum || $ifByYearDay || $ifByMonthDay || $ifByMonthDayNeg || $ifByDay || $ifWDayMaskRel) {
                    unset($daySet[$i]);
                } else {
                    ++$numMatched;
                }
            }

            if (!empty($bySetPos)) {
                $datesAdj  = array();
                $tmpDaySet = array_combine($daySet, $daySet);

                foreach ($bySetPos as $setPos) {
                    if ($setPos < 0) {
                        $dayPos  = (int) floor($setPos / count($timeSet));
                        $timePos = DateUtil::pymod($setPos, count($timeSet));
                    } else {
                        $dayPos  = (int) floor(($setPos - 1) / count($timeSet));
                        $timePos = DateUtil::pymod(($setPos - 1), count($timeSet));
                    }

                    $tmp = array();
                    for ($k = $daySetStart; $k <= $daySetEnd; $k++) {
                        if (!array_key_exists($k, $tmpDaySet)) {
                            continue;
                        }

                        $tmp[] = $tmpDaySet[$k];
                    }

                    if ($dayPos < 0) {
                        $nextInSet = array_slice($tmp, $dayPos, 1);
                        $nextInSet = $nextInSet[0];
                    } else {
                        $nextInSet = $tmp[$dayPos];
                    }

                    /** @var Time $time */
                    $time = $timeSet[$timePos];

                    $dtTmp = DateUtil::getDateTimeByDayOfYear($nextInSet, $dt->format('Y'), $start->getTimezone());

                    $dtTmp->setTime(
                        $time->hour,
                        $time->minute,
                        $time->second
                    );

                    $datesAdj[] = $dtTmp;
                }

                foreach ($datesAdj as $dtTmp) {
                    if (null !== $until && $dtTmp > $until) {
                        $continue = false;
                        break;
                    }

                    if ($dtTmp < $start) {
                        continue;
                    }

                    $cloned = clone $dtTmp;
                    yield $cloned;

                    if ($ignoreCounts) {
                        continue;
                    }

                    if (null !== $count) {
                        --$count;
                        if ($count <= 0) {
                            $continue = false;
                            break;
                        }
                    }

                    ++$total;
                }
            } else {
                foreach ($daySet as $dayOfYear) {
                    $dtTmp = DateUtil::getDateTimeByDayOfYear($dayOfYear, $dt->format('Y'), $start->getTimezone());

                    foreach ($timeSet as $time) {
                        /** @var Time $time */
                        $dtTmp->setTime(
                            $time->hour,
                            $time->minute,
                            $time->second
                        );

                        if (null !== $until && $dtTmp > $until) {
                            $continue = false;
                            break;
                        }

                        if ($dtTmp < $start) {
                            continue;
                        }

                        $cloned = clone $dtTmp;
                        yield $cloned;

                        if ($ignoreCounts) {
                            continue;
                        }

                        if (null !== $count) {
                            --$count;
                            if ($count <= 0) {
                                $continue = false;
                                break;
                            }
                        }

                        ++$total;
                    }

                    if (!$continue) {
                        break;
                    }
                }
            }

            switch ($freq) {
                case Frequency::YEARLY:
                    $year += $rule->getInterval();
                    $month = $dt->format('n');
                    $dt->setDate($year, $month, 1);
                    break;
                case Frequency::MONTHLY:
                    $month += $rule->getInterval();
                    if ($month > 12) {
                        $delta = floor($month / 12);
                        $mod   = DateUtil::pymod($month, 12);
                        $month = $mod;
                        $year += $delta;
                        if ($month == 0) {
                            $month = 12;
                            --$year;
                        }
                    }
                    $dt->setDate($year, $month, 1);
                    break;
                case Frequency::WEEKLY:
                    if ($weekStart > $dtInfo->dayOfWeek) {
                        $delta = ($dtInfo->dayOfWeek + 1 + (6 - $weekStart)) * -1 + $rule->getInterval() * 7;
                    } else {
                        $delta = ($dtInfo->dayOfWeek - $weekStart) * -1 + $rule->getInterval() * 7;
                    }

                    $dt->modify("+$delta day");
                    $year  = $dt->format('Y');
                    $month = $dt->format('n');
                    break;
                case Frequency::DAILY:
                    $dt->modify('+'.$rule->getInterval().' day');
                    $year  = $dt->format('Y');
                    $month = $dt->format('n');
                    break;
                case Frequency::HOURLY:
                    $dt->modify('+'.$rule->getInterval().' hours');
                    $year  = $dt->format('Y');
                    $month = $dt->format('n');
                    $hour  = $dt->format('G');
                    break;
                case Frequency::MINUTELY:
                    $dt->modify('+'.$rule->getInterval().' minutes');
                    $year   = $dt->format('Y');
                    $month  = $dt->format('n');
                    $hour   = $dt->format('G');
                    $minute = $dt->format('i');
                    break;
                case Frequency::SECONDLY:
                    $dt->modify('+'.$rule->getInterval().' seconds');
                    $year   = $dt->format('Y');
                    $month  = $dt->format('n');
                    $hour   = $dt->format('G');
                    $minute = $dt->format('i');
                    $second = $dt->format('s');
                    break;
            }
        }
    }

    /**
     * Returns the smaller of two or more given DateTime objects.
     *
     * In the case of a tie, the first DateTime element is returned.
     *
     * NOTE: `null` is considered the minimal possible value.
     *
     * This is preferred over the default built-in `min` function, which doesn't properly take time zones into account. For
     * example if you have two equal times in UTC and set one to a different time zone, that time is considered "lower"
     * even though it is still has the same timestamp. This method applies a known fix to all arguments which allows them to
     * be properly compared, regardless of the timezone, by first calling `getTimestamp()` to correct the state of the
     * object.
     *
     * @link https://bugs.php.net/bug.php?id=68474
     *
     * @param DateTime[] $datetimes DateTime objects to compare.
     * @return DateTime|null
     */
    private function minDate(array $datetimes)
    {
        array_walk($datetimes, function (DateTime $datetime = null) {
            if (null !== $datetime) {
                $datetime->getTimestamp();
            }
        });

        // Native min function unpacks an array if given as first param.
        return min($datetimes);
    }
}
