<?php

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

namespace Pimcore\DataObject\GridColumnConfig\Operator;

/**
 * @internal
 */
final class Merge extends AbstractOperator
{
    private bool $flatten;

    private bool $unique;

    /**
     * {@inheritdoc}
     */
    public function __construct(\stdClass $config, $context = null)
    {
        parent::__construct($config, $context);

        $this->flatten = $config->flatten ?? false;
        $this->unique = $config->unique ?? false;
    }

    /**
     * {@inheritdoc}
     */
    public function getLabeledValue($element)
    {
        $result = new \stdClass();
        $result->label = $this->label;
        $result->isArrayType = true;

        $childs = $this->getChilds();
        $resultItems = [];

        foreach ($childs as $c) {
            $childResult = $c->getLabeledValue($element);
            $childValues = $childResult->value ?? null;

            if ($this->flatten) {
                if (is_array($childValues)) {
                    foreach ($childValues as $childValue) {
                        if ($childValue) {
                            $resultItems[] = $childValue;
                        }
                    }
                } elseif ($childValues) {
                    $resultItems[] = $childValues;
                }
            } else {
                if ($childValues) {
                    $resultItems[] = $childValues;
                }
            }
        }

        if ($this->getUnique()) {
            $resultItems = array_unique($resultItems);
        }
        $result->value = $resultItems;

        return $result;
    }

    /**
     * @return bool
     */
    public function getFlatten()
    {
        return $this->flatten;
    }

    /**
     * @param bool $flatten
     */
    public function setFlatten($flatten)
    {
        $this->flatten = $flatten;
    }

    /**
     * @return bool
     */
    public function getUnique()
    {
        return $this->unique;
    }

    /**
     * @param bool $unique
     */
    public function setUnique($unique)
    {
        $this->unique = $unique;
    }
}
