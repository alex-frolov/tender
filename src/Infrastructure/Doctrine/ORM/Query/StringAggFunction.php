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
 * Пользовательская DQL-функция STRING_AGG (PostgreSQL).
 *
 * <code>
 * // example:
 * SELECT t.id, STRING_AGG(t.title, ', ') FROM Tender t GROUP BY t.id
 * </code>
 *
 * Используется в TenderRepository::aggregatedStatuses() для DB-агрегации статусов
 * лотов (FR-1.1.3) без raw SQL: STRING_AGG(l.status, ',') по каждому тендеру.
 */
class StringAggFunction extends FunctionNode
{
    public Node $expr;
    public Node $delimiter;

    /**
     * @throws QueryException
     */
    public function getSql(SqlWalker $sqlWalker): string
    {
        return \sprintf(
            'STRING_AGG(%s, %s)',
            $this->expr->dispatch($sqlWalker),
            $this->delimiter->dispatch($sqlWalker),
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
        $parser->match(TokenType::T_COMMA);
        $this->delimiter = $parser->StringPrimary();

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
