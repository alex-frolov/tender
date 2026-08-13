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
 * Пользовательская DQL-функция TO_CHAR (PostgreSQL).
 *
 * <code>
 * // example:
 * SELECT t.id, TO_CHAR(t.createdAt, 'YYYY-MM-DD') FROM Tender t
 * </code>
 *
 * Форматирование дат/чисел на стороне БД (иначе результат зависит от
 * гидратации Doctrine). Используется в TenderRepository::factsByDimension()
 * для среза period (дата создания, Y-m-d) — без raw SQL.
 */
class ToCharFunction extends FunctionNode
{
    public Node $expr;
    public Node $format;

    /**
     * @throws QueryException
     */
    public function getSql(SqlWalker $sqlWalker): string
    {
        return \sprintf(
            'TO_CHAR(%s, %s)',
            $this->expr->dispatch($sqlWalker),
            $this->format->dispatch($sqlWalker),
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
        $this->format = $parser->StringPrimary();

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
