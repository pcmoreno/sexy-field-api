<?php

/*
 * This file is part of the SexyField package.
 *
 * (c) Dion Snoeijen <hallo@dionsnoeijen.nl>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare (strict_types = 1);

namespace Tardigrades\SectionField\Event;

use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\HttpFoundation\Request;
use Tardigrades\SectionField\Generator\CommonSectionInterface;

/**
 * Class ApiEntryUpdated
 *
 * Dispatched after updated entry is saved.
 *
 * @package Tardigrades\SectionField\Event
 */
class ApiEntryUpdated extends Event
{
    const NAME = 'api.entry.updated';

    /** @var Request */
    protected $request;

    /** @var array */
    protected $response;

    /** @var CommonSectionInterface */
    protected $originalEntry;

    /** @var CommonSectionInterface */
    protected $newEntry;

    public function __construct(
        Request $request,
        array $response,
        CommonSectionInterface $originalEntry,
        CommonSectionInterface $newEntry
    ) {
        $this->request = $request;
        $this->response = $response;
        $this->originalEntry = $originalEntry;
        $this->newEntry = $newEntry;
    }

    /** @return Request */
    public function getRequest(): Request
    {
        return $this->request;
    }

    /** @return array */
    public function getResponse(): array
    {
        return $this->response;
    }

    /**
     * The Section Entry Entity that was just persisted
     */
    public function getOriginalEntry(): CommonSectionInterface
    {
        return $this->originalEntry;
    }

    /**
     * The Section Entry Entity that was just persisted
     */
    public function getNewEntry(): CommonSectionInterface
    {
        return $this->newEntry;
    }
}
