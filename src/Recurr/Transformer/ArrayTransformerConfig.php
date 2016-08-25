<?php

/*
 * Copyright 2014 Shaun Simmons
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Recurr\Transformer;

class ArrayTransformerConfig
{
    /** @var int */
    protected $virtualLimit = 732;

    /**
     * Set the virtual limit imposed upon infinitely recurring events.
     *
     * @param int $virtualLimit The limit
     *
     * @return $this
     */
    public function setVirtualLimit($virtualLimit)
    {
        $this->virtualLimit = (int) $virtualLimit;

        return $this;
    }

    /**
     * Get the virtual limit imposed upon infinitely recurring events.
     *
     * @return int
     */
    public function getVirtualLimit()
    {
        return $this->virtualLimit;
    }
}
