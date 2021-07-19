<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\CatalogGraphQl\Model\Resolver\Products\Query;

use Magento\CatalogGraphQl\DataProvider\Product\SearchCriteriaBuilder;
use Magento\CatalogGraphQl\Model\Resolver\Products\DataProvider\ProductSearch;
use Magento\CatalogGraphQl\Model\Resolver\Products\SearchResult;
use Magento\CatalogGraphQl\Model\Resolver\Products\SearchResultFactory;
use Magento\Framework\Api\Search\SearchCriteriaInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\Resolver\ArgumentsProcessorInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\GraphQl\Model\Query\ContextInterface;
use Magento\Search\Api\SearchInterface;
use Magento\Search\Model\Search\PageSizeProvider;

/**
 * Full text search for catalog using given search criteria.
 */
class Search implements ProductQueryInterface
{
    /**
     * @var SearchInterface
     */
    private $search;

    /**
     * @var SearchResultFactory
     */
    private $searchResultFactory;

    /**
     * @var PageSizeProvider
     */
    private $pageSizeProvider;

    /**
     * @var FieldSelection
     */
    private $fieldSelection;

    /**
     * @var ArgumentsProcessorInterface
     */
    private $argsSelection;

    /**
     * @var ProductSearch
     */
    private $productsProvider;

    /**
     * @var ResolveCategoryAggregation
     */
    private $resolveCategoryAggregation;

    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * @param SearchInterface $search
     * @param SearchResultFactory $searchResultFactory
     * @param PageSizeProvider $pageSize
     * @param FieldSelection $fieldSelection
     * @param ProductSearch $productsProvider
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param ArgumentsProcessorInterface|null $argsSelection
     * @param ResolveCategoryAggregation $resolveCategoryAggregation
     */
    public function __construct(
        SearchInterface $search,
        SearchResultFactory $searchResultFactory,
        PageSizeProvider $pageSize,
        FieldSelection $fieldSelection,
        ProductSearch $productsProvider,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        ResolveCategoryAggregation $resolveCategoryAggregation,
        ArgumentsProcessorInterface $argsSelection = null
    ) {
        $this->search = $search;
        $this->searchResultFactory = $searchResultFactory;
        $this->pageSizeProvider = $pageSize;
        $this->fieldSelection = $fieldSelection;
        $this->productsProvider = $productsProvider;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->resolveCategoryAggregation = $resolveCategoryAggregation;
        $this->argsSelection = $argsSelection ?: ObjectManager::getInstance()
            ->get(ArgumentsProcessorInterface::class);
    }

    /**
     * Return product search results using Search API
     *
     * @param array $args
     * @param ResolveInfo $info
     * @param ContextInterface $context
     *
     * @return SearchResult
     *
     * @throws GraphQlInputException
     */
    public function getResult(
        array $args,
        ResolveInfo $info,
        ContextInterface $context
    ): SearchResult {
        $searchCriteria = $this->buildSearchCriteria($args, $info);

        $realPageSize = $searchCriteria->getPageSize();
        $realCurrentPage = $searchCriteria->getCurrentPage();
        //Because of limitations of sort and pagination on search API we will query all IDS
        $pageSize = $this->pageSizeProvider->getMaxPageSize();
        $searchCriteria->setPageSize($pageSize);
        $searchCriteria->setCurrentPage(0);
        $itemsResults = $this->search->search($searchCriteria);

        //Address limitations of sort and pagination on search API apply original pagination from GQL query
        $searchCriteria->setPageSize($realPageSize);
        $searchCriteria->setCurrentPage($realCurrentPage);
        $searchResults = $this->productsProvider->getList(
            $searchCriteria,
            $itemsResults,
            $this->fieldSelection->getProductsFieldSelection($info),
            $context
        );

        $totalPages = $realPageSize ? ((int)ceil($searchResults->getTotalCount() / $realPageSize)) : 0;

        $aggregations = $itemsResults->getAggregations();
        $bucketList = $aggregations->getBuckets();
        $categoryFilter = $args['filter']['category_id'] ?? [];

        if (!empty($categoryFilter) && isset($bucketList[ResolveCategoryAggregation::CATEGORY_BUCKET])) {
            $aggregations = $this->resolveCategoryAggregation->getResolvedCategoryAggregation(
                $categoryFilter,
                $bucketList
            );
        }

        $productArray = [];
        /** @var \Magento\Catalog\Model\Product $product */
        foreach ($searchResults->getItems() as $product) {
            $productArray[$product->getId()] = $product->getData();
            $productArray[$product->getId()]['model'] = $product;
        }

        return $this->searchResultFactory->create(
            [
                'totalCount' => $searchResults->getTotalCount(),
                'productsSearchResult' => $productArray,
                'searchAggregation' => $aggregations,
                'pageSize' => $realPageSize,
                'currentPage' => $realCurrentPage,
                'totalPages' => $totalPages,
            ]
        );
    }

    /**
     * Build search criteria from query input args
     *
     * @param array $args
     * @param ResolveInfo $info
     *
     * @return SearchCriteriaInterface
     *
     * @throws GraphQlInputException
     */
    private function buildSearchCriteria(array $args, ResolveInfo $info): SearchCriteriaInterface
    {
        $productFields = (array)$info->getFieldSelection(1);
        $includeAggregations = isset($productFields['filters']) || isset($productFields['aggregations']);
        $processedArgs = $this->argsSelection->process((string) $info->fieldName, $args);
        $searchCriteria = $this->searchCriteriaBuilder->build($processedArgs, $includeAggregations);

        return $searchCriteria;
    }
}
