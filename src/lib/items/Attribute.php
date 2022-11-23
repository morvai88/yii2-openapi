<?php

/**
 * @copyright Copyright (c) 2018 Carsten Brandt <mail@cebe.cc> and contributors
 * @license https://github.com/cebe/yii2-openapi/blob/master/LICENSE
 */

namespace cebe\yii2openapi\lib\items;

use \Yii;
use cebe\yii2openapi\lib\exceptions\InvalidDefinitionException;
use yii\base\BaseObject;
use yii\db\ColumnSchema;
use yii\helpers\Inflector;
use yii\helpers\StringHelper;
use yii\db\mysql\Schema as MySqlSchema;
use SamIT\Yii2\MariaDb\Schema as MariaDbSchema;
use yii\db\pgsql\Schema as PgSqlSchema;
use yii\base\NotSupportedException;
use function is_array;
use function strtolower;

/**
 * @property-write mixed $default
 * @property-write bool  $isPrimary
 * @property-read string $formattedDescription
 * @property-read null|int $maxLength
 * @property-read null|int $minLength
 */
class Attribute extends BaseObject
{
    /**
     * openApi schema property name
     * @var string
     */
    public $propertyName;

    /**
     * should be string/integer/boolean/float/double/array
     * @var string
     */
    public $phpType = 'string';

    /**
     * model/database column name
     * @var string
     */
    public $columnName;

    /**
     * should be one of \yii\db\Schema types or complete db column definition
     * @var string
     */
    public $dbType = 'string';

    /**
     * Custom db type
     * string | null | false
     * if `false` then this attribute is virtual
     */
    public $xDbType;

    /**
     * nullable
     * bool | null
     */
    public $nullable;

    /**
     * @var string
     */
    public $description = '';

    /**
     * @var bool
     */
    public $readOnly = false;

    /**
     * @var bool
     */
    public $required = false;

    /**
     * related object name, if it exists
     * @var string
     */
    public $reference;
    /**
     * @var int|null (db field length)
     **/
    public $size;

    public $limits = ['min' => null, 'max' => null, 'minLength' => null];

    /**
     * @var bool
     */
    public $primary = false;

    /**
     * @var mixed
     */
    public $defaultValue;

    /**
     * @var array|null
     */
    public $enumValues;

    /**
     * @var string|null
     **/
    public $fakerStub;

    /**
     * @var bool
     **/
    public $isVirtual = false;

    public function __construct(string $propertyName, array $config = [])
    {
        $this->propertyName = $propertyName;
        $this->columnName = $propertyName; // force camel2id ?
        parent::__construct($config);
    }

    public function setPhpType(string $phpType):Attribute
    {
        $this->phpType = $phpType;
        return $this;
    }

    public function setDbType(string $dbType):Attribute
    {
        $this->dbType = $dbType;
        return $this;
    }

    public function setXDbType($xDbType):Attribute
    {
        $this->xDbType = $xDbType;
        return $this;
    }

    public function setNullable($nullable):Attribute
    {
        $this->nullable = $nullable;
        return $this;
    }

    public function setDescription(string $description):Attribute
    {
        $this->description = $description;
        return $this;
    }

    public function setReadOnly(bool $readOnly = true):Attribute
    {
        $this->readOnly = $readOnly;
        return $this;
    }

    public function setRequired(bool $required = true):Attribute
    {
        $this->required = $required;
        return $this;
    }

    public function setSize(?int $size):Attribute
    {
        $this->size = $size;
        return $this;
    }

    public function setDefault($value):Attribute
    {
        $this->defaultValue = $value;
        return $this;
    }

    public function setEnumValues(array $values):Attribute
    {
        $this->enumValues = $values;
        return $this;
    }

    /**
     * @param int|float|null $min
     * @param int|float|null $max
     * @param int|null       $minLength
     * @return $this
     */
    public function setLimits($min, $max, ?int $minLength):Attribute
    {
        $this->limits = ['min' => $min, 'max' => $max, 'minLength' => $minLength];
        return $this;
    }

    public function setFakerStub(?string $fakerStub):Attribute
    {
        $this->fakerStub = $fakerStub;
        return $this;
    }

    public function setIsPrimary(bool $isPrimary = true):Attribute
    {
        $this->primary = $isPrimary;
        return $this;
    }

    public function setIsVirtual(bool $isVirtual = true): Attribute
    {
        $this->isVirtual = $isVirtual;
        return $this;
    }


    public function asReference(string $relatedClass):Attribute
    {
        $this->reference = $relatedClass;
        $this->columnName = $this->propertyName . '_id';
        return $this;
    }

    public function asNonDbReference(string $relatedClass):Attribute
    {
        $this->reference = $relatedClass;
        $this->columnName = $this->propertyName;
        return $this;
    }

    public function isReadOnly():bool
    {
        return $this->readOnly;
    }

    public function isReference():bool
    {
        return $this->reference !== null;
    }

    public function isRequired():bool
    {
        return $this->required;
    }

    public function isVirtual():bool
    {
        return $this->isVirtual;
    }

    public function camelName():string
    {
        return Inflector::camelize($this->propertyName);
    }

    public function getMaxLength():?int
    {
        return $this->size;
    }

    public function getMinLength():?int
    {
        return $this->limits['minLength'];
    }

    public function getFormattedDescription():string
    {
        $comment = $this->columnName.' '.$this->description;
        $type = $this->phpType;
        return $type.' $'.str_replace("\n", "\n * ", rtrim($comment));
    }

    public function toColumnSchema():ColumnSchema
    {
        $column = new ColumnSchema([
            'name' => $this->columnName,
            'phpType'=>$this->phpType,
            'dbType' => strtolower($this->dbType),
            'type' => $this->yiiAbstractTypeForDbSpecificType($this->dbType),
            'allowNull' => $this->allowNull(),
            'size' => $this->size > 0 ? $this->size : null,
        ]);
        $column->isPrimaryKey = $this->primary;
        $column->autoIncrement = $this->primary && $this->phpType === 'int';
        if ($column->type === 'json') {
            $column->allowNull = false;
        }
        if ($this->defaultValue !== null) {
            $column->defaultValue = $this->defaultValue;
        } elseif ($column->allowNull) {
            //@TODO: Need to discuss
            $column->defaultValue = null;
        }
        if (is_array($this->enumValues)) {
            $column->enumValues = $this->enumValues;
        }

        return $column;
    }

    // todo docs - it throw new NotSupportedException
    private function yiiAbstractTypeForDbSpecificType(string $type): string
    {
        if (is_string($this->xDbType) && !empty($this->xDbType)) {
            $xDbType = strtolower($this->xDbType);

            list($isXDbTypeWithArray, $modifiedXDbType) = $this->isTypeWithArray($xDbType);

            if ($this->isMysql()) {
                $mysqlSchema = new MySqlSchema;

                if (!array_key_exists($modifiedXDbType, $mysqlSchema->typeMap)) {
                    throw new InvalidDefinitionException('x-db-type: '.$modifiedXDbType.' is incorrect for MySQL');
                }

                return $mysqlSchema->typeMap[$modifiedXDbType] .
                    ($isXDbTypeWithArray ? '[]' : '');
            } elseif ($this->isMariaDb()) {
                $mariadbSchema = new MariaDbSchema;

                if (!array_key_exists($modifiedXDbType, $mariadbSchema->typeMap)) {
                    throw new InvalidDefinitionException('x-db-type: '.$modifiedXDbType.' is incorrect for MariaDB');
                }
                return $mariadbSchema->typeMap[$modifiedXDbType] .
                    ($isXDbTypeWithArray ? '[]' : '');
            } elseif ($this->isPostgres()) {
                $pgsqlSchema = new PgSqlSchema;
                if (!array_key_exists($modifiedXDbType, $pgsqlSchema->typeMap)) {
                    throw new InvalidDefinitionException('x-db-type: '.$modifiedXDbType.' is incorrect for PostgreSQL');
                }
                return $pgsqlSchema->typeMap[$modifiedXDbType] .
                    ($isXDbTypeWithArray ? '[]' : '');
            } else {
                throw new NotSupportedException('"x-db-type" for database '.get_class(Yii::$app->db->schema).' is not implemented. Only for PostgreSQL, MySQL and MariaDB, it is implemented');
            }
        } else {
            list($isTypeWithArray, $modifiedType) = $this->isTypeWithArray($type);
            if (stripos($type, 'int') === 0) {
                return $isTypeWithArray ? 'integer[]' : 'integer';
            }
            if (stripos($type, 'string') === 0) {
                return $isTypeWithArray ? 'string[]' : 'string';
            }
            if (stripos($type, 'varchar') === 0) {
                return $isTypeWithArray ? 'string[]' : 'string';
            }
            if (stripos($type, 'tsvector') === 0) {
                return $isTypeWithArray ? 'string[]' : 'string';
            }
            if (stripos($type, 'json') === 0) {
                return $isTypeWithArray ? 'json[]' : 'json';
            }
            // TODO? behaviour in Pgsql should remain same but timestamp/datetime bug which is only reproduced in Mysql and Mariadb should be fixed
            if (stripos($type, 'datetime') === 0) {
                return $isTypeWithArray ? 'timestamp[]' : 'timestamp';
            }
        }

        return $type;
    }

    private function allowNull()
    {
        if (is_bool($this->nullable)) {
            return $this->nullable;
        }
        return !$this->isRequired();
    }

    private function isTypeWithArray(string $type): array
    {
        $modifiedType = $type;
        $isDataTypeWithArray = false;

        if (StringHelper::endsWith($type, '[]')) {
            $isDataTypeWithArray = true;
            $modifiedType = str_replace('[]', '', $type); // remove `[]` (array) e.g. 'text[]' -> 'text'
        }
        return [$isDataTypeWithArray, $modifiedType];
    }

    // TODO avoid duplication. also present in lib/ColumnToCode
    private function isPostgres():bool
    {
        return Yii::$app->db->schema instanceof PgSqlSchema;
    }

    private function isMysql():bool
    {
        return (Yii::$app->db->schema instanceof MySqlSchema && !$this->isMariaDb());
    }

    private function isMariaDb():bool
    {
        return strpos(Yii::$app->db->schema->getServerVersion(), 'MariaDB') !== false;
    }
}
