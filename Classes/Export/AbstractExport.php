<?php

declare(strict_types = 1);

namespace Pagemachine\Formlog\Export;

/*
 * This file is part of the Pagemachine TYPO3 Formlog project.
 */

use Pagemachine\Formlog\Rendering\ValueFormatter;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Reflection\ObjectAccess;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Base class for exports (CSV, ...)
 */
abstract class AbstractExport
{
    protected string $fileExtension;

    protected array $configuration = [];

    public function getConfiguration(): array
    {
        return $this->configuration;
    }

    public function setConfiguration(array $configuration): void
    {
        $this->configuration = $configuration;
    }

    abstract public function dump(iterable $items): void;

    /**
     * Get the list of CSV headers
     */
    protected function getHeaders(): array
    {
        $headers = array_column($this->getColumns(), 'label');
        $headers = array_map(function ($header) {

            return  LocalizationUtility::translate($header, 'Formlog') ?: $header;
        }, $headers);

        return $headers;
    }

    /**
     * Get the list of CSV column paths
     */
    protected function getColumnPaths(): array
    {
        $columnPaths = array_column($this->getColumns(), 'property');

        return $columnPaths;
    }

    /**
     * Get the CSV output filename
     */
    protected function getOutputFilename(): string
    {
        $fileBasename = 'output';

        if (!empty($this->configuration['fileBasename'])) {
            $fileBasename = $this->configuration['fileBasename'];
        }

        return sprintf('%s.%s', $fileBasename, $this->fileExtension);
    }

    /**
     * Get the preferred date/time format
     */
    protected function getDateTimeFormat(): string
    {
        $dateTimeFormat = \DateTime::W3C;

        if (!empty($this->configuration['dateTimeFormat'])) {
            $dateTimeFormat = $this->configuration['dateTimeFormat'];
        }

        return $dateTimeFormat;
    }

    /**
     * Generate a list of rows
     *
     * @param array|\Traversable $iterable iterable value
     * @param array $columnPaths List of property paths per column
     */
    protected function generateRows($iterable, array $columnPaths): iterable
    {
        foreach ($iterable as $item) {
            $record = [];

            foreach ($columnPaths as $columnPath) {
                $value = ObjectAccess::getPropertyPath($item, $columnPath);
                $record[] = $this->convertValueToString($value);
            }

            yield $record;
        }
    }

    /**
     * Convert supported values to string
     *
     * @param mixed $value value to convert
     */
    protected function convertValueToString($value): string
    {
        $formatter = GeneralUtility::makeInstance(ValueFormatter::class);

        return $formatter
            ->setDateTimeFormat($this->getDateTimeFormat())
            ->format($value);
    }

    /**
     * @throws \InvalidArgumentException if the column configuration is empty
     */
    private function getColumns(): array
    {
        $columns = $this->configuration['columns'] ?? [];

        if (empty($columns)) {
            throw new \InvalidArgumentException('Export column configuration is empty', 1516620386);
        }

        ksort($columns);

        return $columns;
    }
}
