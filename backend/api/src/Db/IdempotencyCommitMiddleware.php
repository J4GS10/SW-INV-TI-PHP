<?php

declare(strict_types=1);

namespace App\Db;

use App\Http\IdempotencyContext;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Exactly once: when a request with an Idempotency-Key commits its first transaction,
 * the key is marked APPLIED inside that very transaction. The business change and the
 * mark are written together or not at all, whatever service or transaction style
 * made the change (App\Db\Transaccion, Connection::transactional…).
 */
#[AsMiddleware]
final readonly class IdempotencyCommitMiddleware implements Middleware
{
    public function __construct(private IdempotencyContext $context)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        $context = $this->context;

        return new class($driver, $context) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly IdempotencyContext $context)
            {
                parent::__construct($driver);
            }

            public function connect(array $params): Connection
            {
                return new class(parent::connect($params), $this->context) extends AbstractConnectionMiddleware {
                    public function __construct(Connection $connection, private readonly IdempotencyContext $context)
                    {
                        parent::__construct($connection);
                    }

                    public function commit(): void
                    {
                        $key = $this->context->keyToMark();
                        if (null !== $key) {
                            $statement = parent::prepare(
                                "UPDATE \"SYS_IDEMPOTENCY_KEY\" SET \"IDK_Estado\" = 'APPLIED' WHERE \"IDK_Key\" = :k AND \"IDK_Estado\" = 'PENDING'",
                            );
                            $statement->bindValue('k', $key, \Doctrine\DBAL\ParameterType::STRING);
                            $statement->execute();
                            $this->context->markedInTransaction();
                        }
                        try {
                            parent::commit();
                        } catch (\Throwable $e) {
                            $this->context->rolledBack();
                            throw $e;
                        }
                        $this->context->committed();
                    }

                    public function rollBack(): void
                    {
                        $this->context->rolledBack();
                        parent::rollBack();
                    }
                };
            }
        };
    }
}
