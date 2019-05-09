<?php

class PgsqlPlatform extends DefaultPlatform
{

    public function getNativeIdMethod()
    {
        return PropelPlatformInterface::SERIAL;
    }

    public function getAutoIncrement()
    {
        return '';
    }

    /**
     * Escape the string for RDBMS.
     *
     * @param string $text
     *
     * @return string
     */
    public function disconnectedEscapeText($text)
    {
        if (function_exists('pg_escape_string')) {
            return pg_escape_string($text);
        } else {
            return parent::disconnectedEscapeText($text);
        }
    }

    public function getBooleanString($b)
    {
        // parent method does the checking to allow string
        // representations & returns integer
        $b = parent::getBooleanString($b);

        return ($b ? "'t'" : "'f'");
    }

    public function supportsNativeDeleteTrigger()
    {
        return true;
    }

    public function getAddSchemaDDL(Table $table)
    {
        $vi = $table->getVendorInfoForType('pgsql');
        if ($vi->hasParameter('schema')) {
            $pattern = "
CREATE SCHEMA %s;
";

            return sprintf($pattern, $this->quoteIdentifier($vi->getParameter('schema')));
        };
    }

    public function getPrimaryKeyName(Table $table)
    {
        $tableName = $table->getName();

        return $tableName . '_pkey';
    }

    /**
     * @see        Platform::supportsSchemas()
     */
    public function supportsSchemas()
    {
        return true;
    }

    public function hasStreamBlobImpl()
    {
        return true;
    }

    public function supportsVarcharWithoutSize()
    {
        return true;
    }

    /**
     * Overrides the implementation from DefaultPlatform
     *
     * @return string
     * @author     Niklas Närhinen <niklas@narhinen.net>
     * @see        DefaultPlatform::getModifyColumnsDDL
     */
    public function getModifyColumnsDDL($columnDiffs)
    {
        $ret = '';
        foreach ($columnDiffs as $columnDiff) {
            $ret .= $this->getModifyColumnDDL($columnDiff);
        }

        return $ret;
    }

    /**
     * Overrides the implementation from DefaultPlatform
     *
     * @return string
     * @author     Niklas Närhinen <niklas@narhinen.net>
     * @see        DefaultPlatform::getModifyColumnDDL
     */
    public function getModifyColumnDDL(PropelColumnDiff $columnDiff)
    {
        $ret               = '';
        $changedProperties = $columnDiff->getChangedProperties();

        $toColumn = $columnDiff->getToColumn();

        $table = $toColumn->getTable();

        $colName = $this->quoteIdentifier($toColumn->getName());

        $pattern = "
ALTER TABLE %s ALTER COLUMN %s;
";
        foreach ($changedProperties as $key => $property) {
            switch ($key) {
                case 'defaultValueType':
                    continue;
                case 'size':
                case 'type':
                case 'scale':
                    $sqlType = $toColumn->getDomain()->getSqlType();
                    if ($toColumn->isAutoIncrement() && $table && $table->getIdMethodParameters() == null) {
                        $sqlType = $toColumn->getType() === PropelTypes::BIGINT ? 'bigserial' : 'serial';
                    }
                    if ($this->hasSize($sqlType)) {
                        $sqlType .= $toColumn->getDomain()->printSize();
                    }
                    $ret .= sprintf($pattern, $this->quoteIdentifier($table->getName()), $colName . ' TYPE ' . $sqlType);
                    break;
                case 'defaultValueValue':
                    if ($property[0] !== null && $property[1] === null) {
                        $ret .= sprintf($pattern, $this->quoteIdentifier($table->getName()), $colName . ' DROP DEFAULT');
                    } else {
                        $ret .= sprintf($pattern, $this->quoteIdentifier($table->getName()), $colName . ' SET ' . $this->getColumnDefaultValueDDL($toColumn));
                    }
                    break;
                case 'notNull':
                    $notNull = " DROP NOT NULL";
                    if ($property[1]) {
                        $notNull = " SET NOT NULL";
                    }
                    $ret .= sprintf($pattern, $this->quoteIdentifier($table->getName()), $colName . $notNull);
                    break;
            }
        }

        return $ret;
    }

    public function hasSize($sqlType)
    {
        return !("BYTEA" == $sqlType || "TEXT" == $sqlType || "DOUBLE PRECISION" == $sqlType);
    }

    /**
     * Overrides the implementation from DefaultPlatform
     *
     * @return string
     * @author     Niklas Närhinen <niklas@narhinen.net>
     * @see        DefaultPlatform::getAddColumnsDLL
     */
    public function getAddColumnsDDL($columns)
    {
        $ret = '';
        foreach ($columns as $column) {
            $ret .= $this->getAddColumnDDL($column);
        }

        return $ret;
    }

    /**
     * Overrides the implementation from DefaultPlatform
     *
     * @return string
     * @author     Niklas Närhinen <niklas@narhinen.net>
     * @see        DefaultPlatform::getDropIndexDDL
     */
    public function getDropIndexDDL(Index $index)
    {
        if ($index instanceof Unique) {
            $pattern = "
    ALTER TABLE %s DROP CONSTRAINT %s;
    ";

            return sprintf($pattern,
                $this->quoteIdentifier($index->getTable()->getName()),
                $this->quoteIdentifier($index->getName())
            );
        } else {
            return parent::getDropIndexDDL($index);
        }
    }

    /**
     * Get the PHP snippet for getting a Pk from the database.
     * Warning: duplicates logic from DBPostgres::getId().
     * Any code modification here must be ported there.
     */
    public function getIdentifierPhp($columnValueMutator, $connectionVariableName = '$con', $sequenceName = '', $tab = "			")
    {
        if (!$sequenceName) {
            throw new EngineException('PostgreSQL needs a sequence name to fetch primary keys');
        }
        $snippet = "
\$stmt = %s->query(\"SELECT nextval('%s')\");
\$row = \$stmt->fetch(PDO::FETCH_NUM);
%s = \$row[0];";
        $script  = sprintf($snippet,
            $connectionVariableName,
            $sequenceName,
            $columnValueMutator
        );

        return preg_replace('/^/m', $tab, $script);
    }

    /**
     * {@inheritdoc}
     */
    public function getMaxColumnNameLength()
    {
        return 64;
    }

    public function getAddTablesDDL(Database $database)
    {
        $ret = $this->getBeginDDL();
        $ret .= $this->getAddSchemasDDL($database);
        foreach ($database->getTablesForSql() as $table) {
            $ret .= $this->getCommentBlockDDL($table->getName());
            $ret .= $this->getDropTableDDL($table);
            $ret .= $this->getAddTableDDL($table);
            $ret .= $this->getAddIndicesDDL($table);
        }
        foreach ($database->getTablesForSql() as $table) {
            $ret .= $this->getAddForeignKeysDDL($table);
        }
        foreach ($database->getTablesForSql() as $table) {
            $ret .= $this->getAddTriggersDDL($table);
        }
        $ret .= $this->getEndDDL();

        return $ret;
    }

    public function getAddSchemasDDL(Database $database)
    {
        $ret     = '';
        $schemas = [];
        foreach ($database->getTables() as $table) {
            $vi         = $table->getVendorInfoForType('pgsql');
            $schemaName = $vi->hasParameter('schema') ? $vi->getParameter('schema') : $table->getSchema();
            if ($schemaName && !isset($schemas[$schemaName])) {
                $schemas[$schemaName] = true;
                $ret                  .= $this->dropSchemaDDL($schemaName);
                $ret                  .= $this->addSchemaDDL($schemaName);
                $ret                  .= $this->addTriggerTimestamp($schemaName);
            }
        }

        return $ret;
    }

    private function dropSchemaDDL(string $schemaName)
    {
        return PHP_EOL . sprintf('DROP SCHEMA IF EXISTS %s CASCADE;', $schemaName);
    }

    private function addSchemaDDL(string $schemaName)
    {
        return PHP_EOL . sprintf('CREATE SCHEMA %s;', $schemaName) . PHP_EOL;
    }

    private function addTriggerTimestamp(string $schemaName)
    {
        return PHP_EOL . sprintf('CREATE OR REPLACE FUNCTION %s.trigger_set_timestamp()
    RETURNS TRIGGER AS 
$body$
BEGIN 
    NEW.updated_at = NOW()#
    RETURN NEW#
END
$body$ LANGUAGE plpgsql;', $schemaName) . PHP_EOL;
    }

    public function getDropTableDDL(Table $table)
    {
        $ret     = '';
        $ret     .= $this->getUseSchemaDDL($table);
        $pattern = "
DROP TABLE IF EXISTS %s CASCADE;
";
        $ret     .= sprintf($pattern, $this->quoteIdentifier($table->getName()));
        $ret     .= $this->getDropSequenceDDL($table);
        $ret     .= $this->getResetSchemaDDL($table);

        return $ret;
    }

    public function getUseSchemaDDL(Table $table)
    {
        $vi = $table->getVendorInfoForType('pgsql');
        if ($vi->hasParameter('schema')) {
            $pattern = "
SET search_path TO %s;
";

            return sprintf($pattern, $this->quoteIdentifier($vi->getParameter('schema')));
        }
    }

    protected function getDropSequenceDDL(Table $table)
    {
        if ($table->getIdMethod() == IDMethod::NATIVE && $table->getIdMethodParameters() != null) {
            $pattern = "
DROP SEQUENCE %s;
";

            return sprintf($pattern,
                $this->quoteIdentifier(strtolower($this->getSequenceName($table)))
            );
        }
    }

    /**
     * Override to provide sequence names that conform to postgres' standard when
     * no id-method-parameter specified.
     *
     * @param Table $table
     *
     * @return string
     */
    public function getSequenceName(Table $table)
    {
        static $longNamesMap = [];
        $result = null;
        if ($table->getIdMethod() == IDMethod::NATIVE) {
            $idMethodParams = $table->getIdMethodParameters();
            if (empty($idMethodParams)) {
                $result = null;
                // We're going to ignore a check for max length (mainly
                // because I'm not sure how Postgres would handle this w/ SERIAL anyway)
                foreach ($table->getColumns() as $col) {
                    if ($col->isAutoIncrement()) {
                        $result = $table->getName() . '_' . $col->getName() . '_seq';
                        break; // there's only one auto-increment column allowed
                    }
                }
            } else {
                $result = $idMethodParams[0]->getValue();
            }
        }

        return $result;
    }

    public function getResetSchemaDDL(Table $table)
    {
        $vi = $table->getVendorInfoForType('pgsql');
        if ($vi->hasParameter('schema')) {
            return "
SET search_path TO public;
";
        }
    }

    public function getAddTableDDL(Table $table)
    {
        $ret = '';
        $ret .= $this->getUseSchemaDDL($table);
        $ret .= $this->getAddSequenceDDL($table);

        $lines = [];

        foreach ($table->getColumns() as $column) {
            $lines[] = $this->getColumnDDL($column);
        }

        if ($table->hasPrimaryKey()) {
            $lines[] = $this->getPrimaryKeyDDL($table);
        }

        foreach ($table->getUnices() as $unique) {
            $lines[] = $this->getUniqueDDL($unique);
        }

        $sep     = ",
    ";
        $pattern = "
CREATE TABLE %s
(
    %s
);
";
        $ret     .= sprintf($pattern,
            $this->quoteIdentifier($table->getName()),
            implode($sep, $lines)
        );

        if ($table->hasDescription()) {
            $pattern = "
COMMENT ON TABLE %s IS %s;
";
            $ret     .= sprintf($pattern,
                $this->quoteIdentifier($table->getName()),
                $this->quote($table->getDescription())
            );
        }

        $ret .= $this->getAddColumnsComments($table);
        $ret .= $this->getResetSchemaDDL($table);

        return $ret;
    }

    protected function getAddSequenceDDL(Table $table)
    {
        if ($table->getIdMethod() == IDMethod::NATIVE && $table->getIdMethodParameters() != null) {
            $pattern = "
CREATE SEQUENCE %s;
";

            return sprintf($pattern,
                $this->quoteIdentifier(strtolower($this->getSequenceName($table)))
            );
        }
    }

    public function getColumnDDL(Column $col)
    {
        $domain = $col->getDomain();

        $ddl     = [$this->quoteIdentifier($col->getName())];
        $sqlType = $domain->getSqlType();
        $table   = $col->getTable();
        if ($col->isAutoIncrement() && $table && $table->getIdMethodParameters() == null) {
            $sqlType = $col->getType() === PropelTypes::BIGINT ? 'bigserial' : 'serial';
        }
        if ($this->hasSize($sqlType) && $col->isDefaultSqlType($this)) {
            $ddl[] = $sqlType . $domain->printSize();
        } else {
            $ddl[] = $sqlType;
        }
        if ($default = $this->getColumnDefaultValueDDL($col)) {
            $ddl[] = $default;
        }
        if ($notNull = $this->getNullString($col->isNotNull())) {
            $ddl[] = $notNull;
        }
        if ($autoIncrement = $col->getAutoIncrementString()) {
            $ddl[] = $autoIncrement;
        }

        return implode(' ', $ddl);
    }

    public function getUniqueDDL(Unique $unique)
    {
        return sprintf('CONSTRAINT %s UNIQUE (%s)',
            $this->quoteIdentifier($unique->getName()),
            $this->getColumnListDDL($unique->getColumns())
        );
    }

    protected function getAddColumnsComments(Table $table)
    {
        $ret = '';
        foreach ($table->getColumns() as $column) {
            $ret .= $this->getAddColumnComment($column);
        }

        return $ret;
    }

    protected function getAddColumnComment(Column $column)
    {
        $pattern = "
COMMENT ON COLUMN %s.%s IS %s;
";
        if ($description = $column->getDescription()) {
            return sprintf($pattern,
                $this->quoteIdentifier($column->getTable()->getName()),
                $this->quoteIdentifier($column->getName()),
                $this->quote($description)
            );
        }
    }

    private function getAddTriggersDDL($table)
    {
        if (($test = $table->getBehavior('timestampable')) === null || $test->getParameter('disable_updated_at') == 'true') {
            return '';
        }
        $ret = PHP_EOL . "CREATE TRIGGER set_timestamp
        BEFORE UPDATE ON {$table->getName()}
        FOR EACH ROW
        EXECUTE PROCEDURE {$table->getSchema()}.trigger_set_timestamp();" . PHP_EOL;
        return $ret;
    }

    /**
     * Initializes db specific domain mapping.
     */
    protected function initialize()
    {
        parent::initialize();
        $this->setSchemaDomainMapping(new Domain(PropelTypes::BOOLEAN, "BOOLEAN"));
        $this->setSchemaDomainMapping(new Domain(PropelTypes::TINYINT, "INT2"));
        $this->setSchemaDomainMapping(new Domain(PropelTypes::SMALLINT, "INT2"));
        $this->setSchemaDomainMapping(new Domain(PropelTypes::BIGINT, "INT8"));
        $this->setSchemaDomainMapping(new Domain(PropelTypes::REAL, "FLOAT"));
        $this->setSchemaDomainMapping(new Domain(PropelTypes::DOUBLE, "DOUBLE PRECISION"));
        $this->setSchemaDomainMapping(new Domain(PropelTypes::FLOAT, "DOUBLE PRECISION"));
        $this->setSchemaDomainMapping(new Domain(PropelTypes::LONGVARCHAR, "TEXT"));
        $this->setSchemaDomainMapping(new Domain(PropelTypes::BINARY, "BYTEA"));
        $this->setSchemaDomainMapping(new Domain(PropelTypes::VARBINARY, "BYTEA"));
        $this->setSchemaDomainMapping(new Domain(PropelTypes::LONGVARBINARY, "BYTEA"));
        $this->setSchemaDomainMapping(new Domain(PropelTypes::BLOB, "BYTEA"));
        $this->setSchemaDomainMapping(new Domain(PropelTypes::CLOB, "TEXT"));
        $this->setSchemaDomainMapping(new Domain(PropelTypes::OBJECT, "TEXT"));
        $this->setSchemaDomainMapping(new Domain(PropelTypes::ENUM, "INT2"));
        $this->setSchemaDomainMapping(new Domain(PropelTypes::PHP_ARRAY, "JSONB"));
    }
}
