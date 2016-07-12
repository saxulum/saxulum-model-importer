<?php

namespace Saxulum\ModelImporter;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Importer
{
    /**
     * @var ReaderInterface
     */
    protected $reader;

    /**
     * @var WriterInterface
     */
    protected $writer;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @param ReaderInterface $reader
     * @param WriterInterface $writer
     * @param LoggerInterface $logger
     */
    public function __construct(ReaderInterface $reader, WriterInterface $writer, LoggerInterface $logger = null)
    {
        $this->reader = $reader;
        $this->writer = $writer;
        $this->logger = null !== $logger ? $logger : new NullLogger();
    }

    /**
     * @param int $limit
     *
     * @return \DateTime
     */
    public function import($limit = 100)
    {
        $importDate = new \DateTime();

        $this->logger->info('Import started at {importDate}', ['importDate' => $importDate]);

        $offset = 0;

        while ([] !== $readerModels = $this->reader->getModels($offset, $limit)) {
            $this->logger->info('Read, offset: {offset}, limit: {limit}', ['offset' => $offset, 'limit' => $limit]);
            $this->importModels($readerModels, $importDate);
            $this->reader->clear();
            $offset += $limit;
        }

        $this->writer->removeAllOutdated($importDate);
        $this->logger->info('Removed all outdates');

        return $importDate;
    }

    /**
     * @param ReaderModelInterface[]|array $readerModels
     * @param \DateTime                    $importDate
     */
    protected function importModels(array $readerModels, \DateTime $importDate)
    {
        $writerModels = [];
        foreach ($readerModels as $readerModel) {
            try {
                $writerModels[] = $this->importModel($readerModel, $importDate);
            } catch (NotImportableException $e) {
                $this->logger->warning(
                    $e->getMessage(),
                    ['identifier' => $readerModel->getImportIdentifier()]
                );
            }
        }

        $this->writer->flush($writerModels);
        $this->writer->clear();

        $this->logger->info('Flushed models');
    }

    /**
     * @param ReaderModelInterface $readerModel
     * @param \DateTime            $importDate
     *
     * @return WriterModelInterface
     */
    protected function importModel(ReaderModelInterface $readerModel, \DateTime $importDate)
    {
        $writerModel = $this->writer->find($readerModel);
        if (null === $writerModel) {
            $writerModel = $this->writer->create($readerModel);
            $this->modelInfo($readerModel, 'created');
        } else {
            $this->writer->update($writerModel, $readerModel);
            $this->modelInfo($readerModel, 'updated');
        }

        $writerModel->setImportIdentifier($readerModel->getImportIdentifier());
        $writerModel->setLastImportDate($importDate);

        $this->writer->persist($writerModel);

        $this->modelInfo($readerModel, 'persisted');

        return $writerModel;
    }

    /**
     * @param ReaderModelInterface $readerModel
     * @param string               $action
     */
    protected function modelInfo(ReaderModelInterface $readerModel, $action)
    {
        $this->logger->info(
            ucfirst($action).' model with identifier {identifier}',
            ['identifier' => $readerModel->getImportIdentifier()]
        );
    }
}
