<?php

/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace edgardmessias\db\ibm\db2;

use yii\base\InvalidParamException;
use yii\db\Constraint;
use yii\db\Expression;
use yii\db\Query;

/**
 * QueryBuilder is the query builder for DB2 databases.
 * 
 * @property Connection $db Connetion
 *
 * @author Edgard Lorraine Messias <edgardmessias@gmail.com>
 * @author Nikita Verkhovin <vernik91@gmail.com>
 */
class QueryBuilder extends \yii\db\QueryBuilder
{
    public $typeMap = [
        Schema::TYPE_PK => 'integer NOT NULL GENERATED BY DEFAULT AS IDENTITY (START WITH 1, INCREMENT BY 1) PRIMARY KEY',
        Schema::TYPE_BIGPK => 'bigint NOT NULL GENERATED BY DEFAULT AS IDENTITY (START WITH 1, INCREMENT BY 1) PRIMARY KEY',
        Schema::TYPE_STRING => 'varchar(255)',
        Schema::TYPE_TEXT => 'clob',
        Schema::TYPE_SMALLINT => 'smallint',
        Schema::TYPE_INTEGER => 'integer',
        Schema::TYPE_BIGINT => 'bigint',
        Schema::TYPE_FLOAT => 'float',
        Schema::TYPE_DOUBLE => 'double',
        Schema::TYPE_DECIMAL => 'decimal(10,0)',
        Schema::TYPE_DATETIME => 'timestamp',
        Schema::TYPE_TIMESTAMP => 'timestamp',
        Schema::TYPE_TIME => 'time',
        Schema::TYPE_DATE => 'date',
        Schema::TYPE_BINARY => 'blob',
        Schema::TYPE_BOOLEAN => 'smallint',
        Schema::TYPE_MONEY => 'decimal(19,4)',
    ];

    protected function defaultExpressionBuilders()
    {
        return array_merge(parent::defaultExpressionBuilders(), [
            'yii\db\conditions\InCondition' => 'edgardmessias\db\ibm\db2\conditions\InConditionBuilder',
        ]);
    }

    /**
     * Builds a SQL statement for truncating a DB table.
     * @param string $table the table to be truncated. The name will be properly quoted by the method.
     * @return string the SQL statement for truncating a DB table.
     */
    public function truncateTable($table)
    {
        return 'TRUNCATE TABLE ' . $this->db->quoteTableName($table) . ' IMMEDIATE';
    }

    /**
     * @inheritdoc
     */
    public function resetSequence($tableName, $value = null)
    {
        $table = $this->db->getTableSchema($tableName);

        if ($table !== null && isset($table->columns[$table->sequenceName])) {
            if ($value === null) {
                $sql = 'SELECT MAX("'. $table->sequenceName .'") FROM "'. $tableName . '"';
                $value = $this->db->createCommand($sql)->queryScalar() + 1;
            } else {
                $value = (int) $value;
            }
            return 'ALTER TABLE "' . $tableName . '" ALTER COLUMN "'.$table->sequenceName.'" RESTART WITH ' . $value;
        } elseif ($table === null) {
            throw new InvalidParamException("Table not found: $tableName");
        } else {
            throw new InvalidParamException("There is no sequence associated with table '$tableName'.");
        }
    }

    /**
     * Builds a SQL statement for enabling or disabling integrity check.
     * @param boolean $check whether to turn on or off the integrity check.
     * @param string $schema the schema of the tables. Defaults to empty string, meaning the current or default schema.
     * @param string $table the table name. Defaults to empty string, meaning that no table will be changed.
     * @return string the SQL statement for checking integrity
     * @throws \yii\base\NotSupportedException if this is not supported by the underlying DBMS
     * @see http://www-01.ibm.com/support/knowledgecenter/SSEPGG_10.5.0/com.ibm.db2.luw.sql.ref.doc/doc/r0000998.html?cp=SSEPGG_10.5.0%2F2-12-7-227
     */
    public function checkIntegrity($check = true, $schema = '', $table = '')
    {
        if ($table) {
            $tableNames = [$table];
        } else {
            if (!$schema) {
                $schema = $this->db->defaultSchema;
            }

            //Return only tables
            $sql = "SELECT t.tabname FROM syscat.tables AS t"
                    . " WHERE t.type in ('T') AND t.ownertype != 'S'";

            /**
             * Filter by integrity pending
             * @see http://www-01.ibm.com/support/knowledgecenter/SSEPGG_9.7.0/com.ibm.db2.luw.sql.ref.doc/doc/r0001063.html
             */
            if ($check) {
                $sql .= " AND t.status = 'C'";
            }
            if ($schema) {
                $sql .= ' AND t.tabschema = :schema';
            }
            
            $command = $this->db->createCommand($sql);
            if ($schema) {
                $command->bindValue(':schema', $schema);
            }

            $tableNames = $command->queryColumn();
        }

        if (empty($tableNames)) {
            return '';
        }

        $quotedTableNames = [];
        foreach ($tableNames as $tableName) {
            $quotedTableNames[] = $this->db->quoteTableName($tableName) . ($check? '' : ' ALL');
        }

        $enable = $check ? 'CHECKED' : 'UNCHECKED';
        return 'SET INTEGRITY FOR ' . implode(', ', $quotedTableNames) . ' IMMEDIATE ' . $enable. ';';
    }

    /**
     * @inheritdoc
     */
    public function buildOrderByAndLimit($sql, $orderBy, $limit, $offset)
    {
        $limitOffsetStatment = $this->buildLimit($limit, $offset);
        if ($limitOffsetStatment != '') {
            $sql = str_replace(':query', $sql, $limitOffsetStatment);

            //convert "item"."id" to "id" to use in OVER()
            $newOrderBy = [];

            if(!empty($orderBy)){
                foreach ($orderBy as $name => $direction) {
                    if(is_string($name)){
                        $e = explode('.', $name);
                        $name = array_pop($e);
                    }
                    $newOrderBy[$name] = $direction;
                }
            }

            $orderByStatment = $this->buildOrderBy($newOrderBy);

            $sql = str_replace(':order', $orderByStatment,$sql);
        }else{
            $orderByStatment = $this->buildOrderBy($orderBy);
            if ($orderByStatment !== '') {
                $sql .= $this->separator . $orderByStatment;
            }
        }
        return $sql;
    }

    /**
     * @inheritdoc
     */
    public function buildLimit($limit, $offset)
    {
        if (!$this->hasLimit($limit) && !$this->hasOffset($offset)) {
            return '';
        }

        if (!$this->hasOffset($offset)) {
            return ':query FETCH FIRST ' . $limit . ' ROWS ONLY';
        }

        /**
         * @todo Need remote the `RN_` from result to use in "INSERT" query
         */
        $limitOffsetStatment = 'SELECT * FROM (SELECT SUBQUERY_.*, ROW_NUMBER() OVER(:order) AS RN_ FROM ( :query ) AS SUBQUERY_) as t WHERE :offset :limit';

        $replacement = $this->hasOffset($offset) ? 't.RN_ > ' . $offset : 't.RN_ > 0';
        $limitOffsetStatment = str_replace(':offset', $replacement, $limitOffsetStatment);

        $replacement = $this->hasLimit($limit) ? 'AND t.RN_ <= ' . ($limit + $offset) : '';
        $limitOffsetStatment = str_replace(':limit', $replacement, $limitOffsetStatment);

        return $limitOffsetStatment;
    }

    /**
     * @inheritdoc
     */
    public function alterColumn($table, $column, $type)
    {
        return 'ALTER TABLE ' . $this->db->quoteTableName($table) . ' ALTER COLUMN '
        . $this->db->quoteColumnName($column) . ' SET DATA TYPE '
        . $this->getColumnType($type);
    }

    /**
     * @inheritdoc
     */
    public function prepareInsertValues($table, $columns, $params = [])
    {
        $result = parent::prepareInsertValues($table, $columns, $params);

        // Empty placeholders, replace for (DEFAULT, DEFAULT, ...)
        if (empty($result[1]) && $result[2] === ' DEFAULT VALUES') {
            $schema = $this->db->getSchema();
            if (($tableSchema = $schema->getTableSchema($table)) !== null) {
                $columnSchemas = $tableSchema->columns;
            } else {
                $columnSchemas = [];
            }
            $result[1] = array_fill(0, count($columnSchemas), 'DEFAULT');
        }

        return $result;
    }
    
    /**
     * @inheritdoc
     */
    public function upsert($table, $insertColumns, $updateColumns, &$params)
    {
        /** @var Constraint[] $constraints */
        list($uniqueNames, $insertNames, $updateNames) = $this->prepareUpsertColumns($table, $insertColumns, $updateColumns, $constraints);
        if (empty($uniqueNames)) {
            return $this->insert($table, $insertColumns, $params);
        }

        $onCondition = ['or'];
        $quotedTableName = $this->db->quoteTableName($table);
        foreach ($constraints as $constraint) {
            $constraintCondition = ['and'];
            foreach ($constraint->columnNames as $name) {
                $quotedName = $this->db->quoteColumnName($name);
                $constraintCondition[] = "$quotedTableName.$quotedName=\"EXCLUDED\".$quotedName";
            }
            $onCondition[] = $constraintCondition;
        }
        $on = $this->buildCondition($onCondition, $params);
        list(, $placeholders, $values, $params) = $this->prepareInsertValues($table, $insertColumns, $params);
        if (!empty($placeholders)) {
            $usingSelectValues = [];
            foreach ($insertNames as $index => $name) {
                $usingSelectValues[$name] = new Expression($placeholders[$index]);
            }
            $usingSubQuery = (new Query())
                ->select($usingSelectValues)
                ->from('SYSIBM.SYSDUMMY1');
            list($usingValues, $params) = $this->build($usingSubQuery, $params);
        }
        $mergeSql = 'MERGE INTO ' . $this->db->quoteTableName($table) . ' '
            . 'USING (' . (isset($usingValues) ? $usingValues : ltrim($values, ' ')) . ') "EXCLUDED" '
            . "ON ($on)";
        $insertValues = [];
        foreach ($insertNames as $name) {
            $quotedName = $this->db->quoteColumnName($name);
            if (strrpos($quotedName, '.') === false) {
                $quotedName = '"EXCLUDED".' . $quotedName;
            }
            $insertValues[] = $quotedName;
        }
        $insertSql = 'INSERT (' . implode(', ', $insertNames) . ')'
            . ' VALUES (' . implode(', ', $insertValues) . ')';
        if ($updateColumns === false) {
            return "$mergeSql WHEN NOT MATCHED THEN $insertSql";
        }

        if ($updateColumns === true) {
            $updateColumns = [];
            foreach ($updateNames as $name) {
                $quotedName = $this->db->quoteColumnName($name);
                if (strrpos($quotedName, '.') === false) {
                    $quotedName = '"EXCLUDED".' . $quotedName;
                }
                $updateColumns[$name] = new Expression($quotedName);
            }
        }
        list($updates, $params) = $this->prepareUpdateSets($table, $updateColumns, $params);
        $updateSql = 'UPDATE SET ' . implode(', ', $updates);
        return "$mergeSql WHEN MATCHED THEN $updateSql WHEN NOT MATCHED THEN $insertSql";
    }

    /**
     * Creates a SELECT EXISTS() SQL statement.
     * @param string $rawSql the subquery in a raw form to select from.
     * @return string the SELECT EXISTS() SQL statement.
     *
     * @since 2.0.8
     */
    public function selectExists($rawSql)
    {
        return 'SELECT CASE WHEN COUNT(*)>0 THEN 1 ELSE 0 END FROM (' . $rawSql . ') CHECKEXISTS';;
    }

    /**
     * Builds a SQL command for adding comment to column
     *
     * @param string $table the table whose column is to be commented. The table name will be properly quoted by the method.
     * @param string $column the name of the column to be commented. The column name will be properly quoted by the method.
     * @return string the SQL statement for adding comment on column
     * @since 2.0.8
     */
    public function dropCommentFromColumn($table, $column)
    {
        return 'COMMENT ON COLUMN ' . $this->db->quoteTableName($table) . '.' . $this->db->quoteColumnName($column) . " IS ''";
    }

    /**
     * Builds a SQL command for adding comment to table
     *
     * @param string $table the table whose column is to be commented. The table name will be properly quoted by the method.
     * @return string the SQL statement for adding comment on column
     * @since 2.0.8
     */
    public function dropCommentFromTable($table)
    {
        return 'COMMENT ON TABLE ' . $this->db->quoteTableName($table) . " IS ''";
    }
}
