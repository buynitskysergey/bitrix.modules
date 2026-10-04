<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Category;

/**
 * The contract of {@see AbstractCategoryDto} as an object: {@see \Bitrix\Rest\V3\Dto\Dto} builds its
 * field set in the constructor and keys the cache of that set by class name, so the generator needs a
 * concrete class with a stable name to read the fields from. No route answers with this class - the
 * DTO a route uses is generated per entity type by {@see CategoryDtoGenerator}.
 */
final class CategoryDto extends AbstractCategoryDto
{
}
