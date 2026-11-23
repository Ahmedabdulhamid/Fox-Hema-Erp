<?php

namespace App\Modules\BackupRestore\Services;

/** Validates a bounded expression language; never evaluates or executes expressions. */
class RecoveryGeneratedExpression
{
    /** @var list<array{string, string}> */
    private array $tokens = [];

    private int $position = 0;

    /** @var list<string> */
    private array $columns = [];

    /** @param list<string> $columns Physical columns in this CREATE TABLE only. */
    public function validate(string $expression, array $columns): void
    {
        $this->tokens = [];
        $this->position = 0;
        $this->columns = array_map(strtolower(...), $columns);
        if (strlen($expression) > 8192) {
            $this->reject();
        }
        $pattern = "~\\G(?:\\s+|(?<identifier>`[a-zA-Z_][a-zA-Z0-9_]{0,63}`)|(?<literal>'(?:[^'\\\\]|\\\\.|'')*')|(?<number>\\d+(?:\\.\\d+)?)|(?<word>[a-zA-Z_][a-zA-Z0-9_]*)|(?<operator><=|>=|<>|!=|[()=<>+*,%\\-]))~s";
        $offset = 0;
        while ($offset < strlen($expression)) {
            if (! preg_match($pattern, $expression, $match, PREG_UNMATCHED_AS_NULL, $offset)) {
                $this->reject();
            }
            $offset += strlen($match[0]);
            foreach (['identifier', 'literal', 'number', 'word', 'operator'] as $kind) {
                if (($match[$kind] ?? null) !== null) {
                    $this->tokens[] = [$kind, $kind === 'word' ? strtoupper($match[$kind]) : $match[$kind]];
                    break;
                }
            }
            if (count($this->tokens) > 512) {
                $this->reject();
            }
        }
        $this->expression(0, 0);
        if ($this->position !== count($this->tokens)) {
            $this->reject();
        }
    }

    private function expression(int $minimum, int $depth): void
    {
        if ($depth > 32) {
            $this->reject();
        }
        $this->primary($depth + 1);
        $precedence = ['OR' => 1, 'AND' => 2, '=' => 3, '!=' => 3, '<>' => 3, '<' => 3, '>' => 3,
            '<=' => 3, '>=' => 3, 'IS' => 3, 'IN' => 3, 'NOT' => 3, '+' => 4, '-' => 4, '*' => 5, '%' => 5];
        while (($level = $precedence[$this->peek()] ?? -1) >= $minimum) {
            $operator = $this->take()[1];
            if ($operator === 'IS') {
                $this->accept('NOT');
                $this->expect('NULL');
            } elseif ($operator === 'IN' || $operator === 'NOT') {
                if ($operator === 'NOT') {
                    $this->expect('IN');
                }
                $this->expect('(');
                $this->expression(0, $depth + 1);
                while ($this->accept(',')) {
                    $this->expression(0, $depth + 1);
                }
                $this->expect(')');
            } else {
                $this->expression($level + 1, $depth + 1);
            }
        }
    }

    private function primary(int $depth): void
    {
        if ($depth > 32) {
            $this->reject();
        }
        $token = $this->take();
        if ($token[0] === 'identifier') {
            if (! in_array(strtolower(trim($token[1], '`')), $this->columns, true)) {
                $this->reject();
            }
        } elseif (in_array($token[0], ['literal', 'number'], true) || in_array($token[1], ['NULL', 'TRUE', 'FALSE'], true)) {
            return;
        } elseif ($token[1] === '(') {
            $this->expression(0, $depth);
            $this->expect(')');
        } elseif (in_array($token[1], ['+', '-', 'NOT'], true)) {
            $this->expression($token[1] === 'NOT' ? 3 : 6, $depth);
        } elseif ($token[1] === 'CASE') {
            $this->expect('WHEN');
            do {
                $this->expression(0, $depth);
                $this->expect('THEN');
                $this->expression(0, $depth);
            } while ($this->accept('WHEN'));
            if ($this->accept('ELSE')) {
                $this->expression(0, $depth);
            }
            $this->expect('END');
        } else {
            $this->reject();
        }
    }

    private function peek(): string
    {
        return $this->tokens[$this->position][1] ?? '';
    }

    /** @return array{string, string} */
    private function take(): array
    {
        if (! isset($this->tokens[$this->position])) {
            $this->reject();
        }

        return $this->tokens[$this->position++];
    }

    private function accept(string $value): bool
    {
        if ($this->peek() !== $value) {
            return false;
        }
        $this->position++;

        return true;
    }

    private function expect(string $value): void
    {
        if (! $this->accept($value)) {
            $this->reject();
        }
    }

    private function reject(): never
    {
        throw new BackupFailure('restore_sql_unsupported');
    }
}
