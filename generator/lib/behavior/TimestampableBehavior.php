<?php
/**
 * Created by aXtion B.V.
 * Date: 8-9-2016
 *
 * @author Duncan de Boer <duncan.de.boer@axtion.nl>
 */

class TimestampableBehavior extends Behavior
{
    // default parameters value
    protected $parameters = [
        'create_column'      => 'created_at',
        'update_column'      => 'updated_at',
        'disable_updated_at' => 'false',
    ];

    public function objectMethods(PHP5ObjectBuilder $builder)
    {
        if ($this->withUpdatedAt()) {
            return "
/**
 * Mark the current object so that the update date doesn't get updated during next save
 *
 * @return     " . $builder->getStubObjectBuilder()->getClassname() . " The current object (for fluent API support)
 */
public function keepUpdateDateUnchanged()
{
    \$this->modifiedColumns[] = " . $this->getColumnConstant('update_column', $builder) . ";

    return \$this;
}
";
        }
    }

    protected function withUpdatedAt()
    {
        return 'true' !== $this->getParameter('disable_updated_at');
    }

    /**
     * Return the constant for a given column.
     *
     * @param string $columnName
     * @param OMBuilder $builder
     *
     * @return string
     */
    protected function getColumnConstant($columnName, OMBuilder $builder)
    {
        return $builder->getColumnConstant($this->getColumnForParameter($columnName));
    }

    public function queryMethods(QueryBuilder $builder)
    {
        $script = '';

        $queryClassName       = $builder->getStubQueryBuilder()->getClassname();
        $createColumnConstant = $this->getColumnConstant('create_column', $builder);

        if ($this->withUpdatedAt()) {
            $updateColumnConstant = $this->getColumnConstant('update_column', $builder);

            $script .= "
/**
 * Filter by the latest updated
 *
 * @param      int \$nbDays Maximum age of the latest update in days
 *
 * @return     $queryClassName The current query, for fluid interface
 */
public function recentlyUpdated(\$nbDays = 7)
{
    return \$this->addUsingAlias($updateColumnConstant, time() - \$nbDays * 24 * 60 * 60, Criteria::GREATER_EQUAL);
}

/**
 * Order by update date desc
 *
 * @return     $queryClassName The current query, for fluid interface
 */
public function lastUpdatedFirst()
{
    return \$this->addDescendingOrderByColumn($updateColumnConstant);
}

/**
 * Order by update date asc
 *
 * @return     $queryClassName The current query, for fluid interface
 */
public function firstUpdatedFirst()
{
    return \$this->addAscendingOrderByColumn($updateColumnConstant);
}
";
        }

        $script .= "
/**
 * Filter by the latest created
 *
 * @param      int \$nbDays Maximum age of in days
 *
 * @return     $queryClassName The current query, for fluid interface
 */
public function recentlyCreated(\$nbDays = 7)
{
    return \$this->addUsingAlias($createColumnConstant, time() - \$nbDays * 24 * 60 * 60, Criteria::GREATER_EQUAL);
}

/**
 * Order by create date desc
 *
 * @return     $queryClassName The current query, for fluid interface
 */
public function lastCreatedFirst()
{
    return \$this->addDescendingOrderByColumn($createColumnConstant);
}

/**
 * Order by create date asc
 *
 * @return     $queryClassName The current query, for fluid interface
 */
public function firstCreatedFirst()
{
    return \$this->addAscendingOrderByColumn($createColumnConstant);
}";

        return $script;
    }

    public function preInsert(PHP5ObjectBuilder $builder)
    {
    }

    /**
     * Add the create_column and update_columns to the current table
     */
    public function modifyTable()
    {
        $this->addColumn($this->getParameter('create_column'));

        if ($this->withUpdatedAt()) {
            $this->addColumn($this->getParameter('update_column'));
        }
    }

    private function addColumn($name)
    {
        if (!$this->getTable()->hasColumn($name)) {
            $this->getTable()->addColumn([
                'name' => $name,
                'type' => 'TIMESTAMP'
            ]);
        }
        $column = $this->getTable()->getColumn($name);
        $this->getTable()->removeColumn($name);

        $column->setNotNull(true);
        $column->setDefaultValue(new ColumnDefaultValue('CURRENT_TIMESTAMP', ColumnDefaultValue::TYPE_EXPR));
        $column->setType('TIMESTAMP');
        $this->getTable()->addColumn($column);
    }

    public function preUpdate(PHP5ObjectBuilder $builder)
    {
        return '';
    }

    /**
     * Get the setter of one of the columns of the behavior
     *
     * @param string $column One of the behavior columns, 'create_column' or 'update_column'
     *
     * @return string The related setter, 'setCreatedOn' or 'setUpdatedOn'
     */
    protected function getColumnSetter($column)
    {
        return 'set' . $this->getColumnForParameter($column)->getPhpName();
    }
}
