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
use Recurr\DateExclusion;
use Recurr\DateInclusion;
use Recurr\DateUtil;
use Recurr\Exception\MissingData;
use Recurr\Recurrence;
use Recurr\RecurrenceCollection;
use Recurr\Rule;

/**
 * This class is responsible for transforming a Rule in to an array
 * of \DateTime() objects.
 *
 * If a recurrence rule is infinitely recurring, a virtual limit is imposed.
 *
 * @package Recurr
 * @author  Shaun Simmons <shaun@envysphere.com>
 */
class ArrayTransformer
{
    /** @var ArrayTransformerConfig */
    protected $config;

    /**
     * Some versions of PHP are affected by a bug where
     * \DateTime::createFromFormat('z Y', ...) does not account for leap years.
     *
     * @var bool
     */
    protected $leapBug = false;

    /**
     * @var InstanceGenerator
     */
    private $generator;

    /**
     * Construct a new ArrayTransformer
     *
     * @param ArrayTransformerConfig $config
     * @param InstanceGenerator $generator
     */
    public function __construct(ArrayTransformerConfig $config = null, InstanceGenerator $generator = null)
    {
        if (!$config instanceof ArrayTransformerConfig) {
            $config = new ArrayTransformerConfig();
        }

        $this->config = $config;
        $this->generator = $generator ?: new InstanceGenerator();

        $this->leapBug = DateUtil::hasLeapYearBug();
    }

    /**
     * @param ArrayTransformerConfig $config
     */
    public function setConfig($config)
    {
        $this->config = $config;
    }

    /**
     * Transform a Rule in to an array of \DateTimes
     *
     * @param Rule                     $rule                    the Rule object
     * @param ConstraintInterface|null $constraint              Potential recurrences must pass the constraint, else
     *                                                          they will not be included in the returned collection.
     * @param bool                     $countConstraintFailures Whether recurrences that fail the constraint's test
     *                                                          should count towards a rule's COUNT limit.
     *
     * @return RecurrenceCollection
     * @throws MissingData
     */
    public function transform(Rule $rule, ConstraintInterface $constraint = null, $countConstraintFailures = true)
    {
        $start = $rule->getStartDate();
        $end   = $rule->getEndDate();
        $until = $rule->getUntil();

        $vLimit = null !== $this->config->getVirtualLimit() && $countConstraintFailures
            ? $this->config->getVirtualLimit()
            : $rule->getCount();

        if (null === $start) {
            $start = new DateTime('now', $until instanceof \DateTime ? $until->getTimezone() : null);
        }

        if (null === $rule) {
            throw new MissingData('Rule has not been set');
        }

        if (null === $end) {
            $end = $start;
        }

        $durationInterval = $start->diff($end);

        $instance_generator = $this->generator->generate(
            $start,
            [$rule],
            array_map(function (DateInclusion $date) {
                return $date->date;
            }, $rule->getRDates()),
            [],
            array_map(function (DateExclusion $date) {
                return $date->date;
            }, $rule->getExDates()),
            !$countConstraintFailures
        );

        $recurrences = [];

        $i = 0;
        foreach ($instance_generator as $instance) {
            if (null !== $constraint && !$constraint->test($instance)) {
                if (!$countConstraintFailures) {
                    if ($constraint->stopsTransformer()) {
                        break;
                    } else {
                        continue;
                    }
                }
            } else {
                $instance_end = clone $instance;
                $recurrences[] = new Recurrence($instance, $instance_end->add($durationInterval));
            }

            if (null !== $vLimit && ++$i >= $vLimit) {
                break;
            }
        }

        return new RecurrenceCollection($recurrences);
    }
}
