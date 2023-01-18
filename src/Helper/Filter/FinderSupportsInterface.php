<?php

namespace Paknahad\JsonApiBundle\Helper\Filter;

use Paknahad\JsonApiBundle\Helper\FieldManager;
use Symfony\Component\HttpFoundation\Request;

interface FinderSupportsInterface extends FinderInterface
{
    /**
     * Whether this finder can operate with the giver Request and FieldManager.
     */
    public function supports(Request $request, FieldManager $fieldManager): bool;
}
