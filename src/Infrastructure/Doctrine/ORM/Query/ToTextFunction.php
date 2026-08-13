<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\ORM\Query;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\QueryException;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * Пользовательская DQL-функция TO_TEXT (PostgreSQL).
 *
 * <code>
 * // example:
 * SELECT t.id, TO_TEXT(t.customerId) FROM Tender t
 * </code>
 *
 * Приведение поля/выражения к text на стороне БД — аналог `CAST(x AS text)`
 * (для uuid/числовых идентификаторов, когда нужно строковое значение среза).
 * Используется в TenderRepository::factsByDimension() для среза customer.
 */
class ToTextFunction extends FunctionNode
{
    public Node $expr;

    /**
     * @throws QueryException
     */
    public function getSql(SqlWalker $sqlWalker): string
    {
        return \sprintf(
            'CAST(%s AS text)',
            $this->expr->dispatch($sqlWalker),
        );
    }

    /**
     * @throws QueryException
     */
    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);

        $this->expr = $parser->StringPrimary();

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
