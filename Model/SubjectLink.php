<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\CustomerSso\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * Storage for the IdP-subject → customer link (see etc/db_schema.xml).
 *
 * The subject (`sub`) is the stable re-login key: once a customer is linked, every
 * later SSO login resolves them by subject rather than by the mutable email. This
 * lives in its own table because storefront customers are EAV/API entities — unlike
 * `admin_user`, where {@see \MageDevGroup\AdminSso\Model\UserProvisioner} keeps the
 * subject as a plain column on the table.
 */
class SubjectLink
{
    /** Link table name (see etc/db_schema.xml). */
    public const TABLE = 'magedevgroup_customer_sso_subject';

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Customer id linked to the given IdP subject under the given provider, or null.
     *
     * Scoped by provider code because `sub` is only unique per issuer: a collision
     * across providers must not resolve to another provider's customer.
     *
     * @param string $providerCode active SSO provider code
     * @param string $subjectId
     */
    public function findCustomerIdBySubject(string $providerCode, string $subjectId): ?int
    {
        if ($providerCode === '' || $subjectId === '') {
            return null;
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE), 'customer_id')
            ->where('provider_code = ?', $providerCode)
            ->where('subject_id = ?', $subjectId)
            ->limit(1);

        $customerId = $connection->fetchOne($select);

        return $customerId === false || $customerId === null || $customerId === ''
            ? null
            : (int)$customerId;
    }

    /**
     * IdP identity (provider code + subject) linked to the given customer, or null.
     *
     * Returns the full pair because `sub` is only unique per issuer: the same subject
     * string under a different provider is a different identity, so the caller's
     * takeover guard must compare provider code too.
     *
     * @param int $customerId
     * @return array{provider_code:string,subject_id:string}|null
     */
    public function getIdentityByCustomerId(int $customerId): ?array
    {
        if ($customerId <= 0) {
            return null;
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE), ['provider_code', 'subject_id'])
            ->where('customer_id = ?', $customerId)
            ->limit(1);

        $row = $connection->fetchRow($select);

        return is_array($row) && isset($row['provider_code'], $row['subject_id'])
            && $row['provider_code'] !== '' && $row['subject_id'] !== ''
            ? ['provider_code' => (string)$row['provider_code'], 'subject_id' => (string)$row['subject_id']]
            : null;
    }

    /**
     * Persist (or refresh) the subject link for a customer. Idempotent.
     *
     * @param int $customerId
     * @param string $providerCode active SSO provider code that issued the subject
     * @param string $subjectId
     */
    public function link(int $customerId, string $providerCode, string $subjectId): void
    {
        if ($customerId <= 0 || $providerCode === '' || $subjectId === '') {
            return;
        }

        $connection = $this->resource->getConnection();
        $connection->insertOnDuplicate(
            $this->resource->getTableName(self::TABLE),
            ['customer_id' => $customerId, 'provider_code' => $providerCode, 'subject_id' => $subjectId],
            ['provider_code', 'subject_id']
        );
    }
}
