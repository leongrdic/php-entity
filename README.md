# php-entity
## Getting started
An entity is an object that represents a single row in a specific table in a database of choice.

Entity class defines which database and table the entities are stored in and information about the table columns, and also the relations between different entity classes.

An entity has properties which can either be `indexed`, which means they are stored in same-named columns in the table, or `non-indexed` which means they stored encoded with other `non-indexed` properties in a single table column. The purpose of `indexed` properties is the ability to find entities with certain properties set, while `unindexed` properties are meant for data that's only accessed once the entity is already found.

Entities can also have optional hashes which are generated on each update of the entity properties, and can be used to check if there were any changes from the cached version of an entity.

Each entity must have an ID which can be either numeric or a string (e.g. UUID).

### Using Composer
You can install `php-entity` using
```
php composer.phar require leongrdic/entity
```

### Not using Composer
You can simply require the `Entity.php` file from the `src/` folder at the beginning of your script:
```php
require_once('Entity.php');
```

### Database class
Currently supported database modules are: [`php-db-pdo-mysql`](https://github.com/leongrdic/php-db-pdo-mysql)

Firstly, you need a database singleton class containing the database object in a public static variable `$instance`:
```php
class EntityDB { public static $instance; }
$instance = ...; // your database object initialization
```

### Initialization
For this example, we'll be demonstrating initialization of a session entity class.

```php
class Session extends \Le\Entity {
  protected static
    $db = EntityDB::class,
    $table = 'session',
    $column_id = 'id', // this value is set to 'id' by default
    $column_data = 'data', // this value is set to 'data' by default
    $column_hash = 'hash', // hashes are optional, and turned off by default
    $indexes = ['token', 'ip_address'],
    $parents = [
      'user' => User::class // column => class
    ]
	;
}
```

If your entity class uses any advantages of the entity relations (parents & children specification), it's also necessary to add the entity class to the list of initialized classes:
```php
array_push(\Le\Entity::$initialized, Session::class);
```

## Static methods
All following methods can only be called on classes that extend the `\Le\Entity` class.

### `load($id)`
#### Parameters
`$id` is the id of the entity you're loading

#### Return
Returns the entity `object`.

If the entity can't be found, an `EntityNotFoundException` is thrown.

### `hash($id, $hash)`
Compares a hash of an entity to a provided hash.

#### Parameters
`$id` is the id of the entity you're checking

`$hash` is the hash we're comparing

#### Return
Returns `true` if the hashes match, or `false` if they don't (or the entity class doesn't use hashes).

If the entity can't be found, an `EntityNotFoundException` is thrown.

### `find($conditions, $additional, $return_objects)`
#### Parameters
`$conditions` and `$additional` are passed to the database module

`$return_objects` determines whether you want just `id` and `hash` to be returned as an array, or the entity `object` with all properties; defaults to false

#### Return
If `$return_objects` is `false`
```php
[
  'count' = > 3,
  'data' => [
    [
      'id' => 1,
      'hash' => 16329136 // only if hashes are enabled for entity class
    ],
    ...
  ]
]
```

If `$return_objects` is `false` and `single` is `true`
```php
[
  'count' = > 3,
  'data' => [
    'id' => 1,
    'hash' => 16329136 // only if hashes are enabled for entity class
  ],
  ...
]
```

If `$return_objects` is `true`
```php
[
  'count' => 3,
  'data' => [ Object, Object, Object ]
]
```

If `$return_objects` is `true` and additional `single` is `true`
```php
[
  'count' => 3,
  'object' => Object
]
```

### `create($data)`
#### Parameters
`$data` is an array containing key-value pairs of properties to be set on the new entity

#### Return
Returns an `object` representing the newly created entity.

### `delete($id)`
Deletes an entity and all of its children recursively.

#### Parameters
`$id` is the id of the entity you want to delete

### Return
Returns `true` if successful.

## Object methods
### `get($property)`
#### Parameters
`$property` is the name of the property you want to fetch; if left empty or not provided, an array containing all properties will be returned

#### Return
The `value` of the requested property or an `array` containing key-value pairs of all properties if no parameter specified.

### `set($properties)`
All unindexed properties are automatically grouped and encoded.

#### Parameters
`$properties` is an array containing key-value pairs of all properties that we need to update.

#### Return
Returns `true` if successful.

### `reindex()`
The purpose of this method is to regenerate the unindexed data column in the database in case there were changes in the table schema (e.g. an unindexed column is now indexed, or vice versa).

#### Return
Returns `true` if successful.

### `parent($class, $return_objects)`

#### Parameters
`$class` is the class name (e.g. User::class) of the parent we're looking for

`$return_objects` shares behavior with the `find()` static method

#### Return
The return value is same as for `find()` static method.

### `children($class, $conditions, $additional, $return_objects)`
#### Parameters
`$class` is the class name (e.g. User::class) of the children we're looking for

`$conditions`, `$additional` and `$return_objects` share the behavior with the same parameters for the `find()` static method

#### Return
The return value is same as for `find()` static method.

## Error handling & debugging

Debugging can help you determine mistakes in your code that utilizes `php-entity` like invalid parameter formats or wrong data types.
To enable debugging, set the static variable `$debug` of the class to `true`:
```php
\Le\Entity::$debug = true;
```

When debugging is turned on, the methods can throw `Error`s containing information about what went wrong.

Besides the debugging errors, `php-db-pdo-mysql` throws some exceptions like `EntityNotFoundException`.

To ensure your actions were finished successfully use a `try-catch` block around the methods that throw the exceptions:
```php
try {
  $entity = User::load($id);
}
catch(EntityNotFoundException $e){
  echo 'entity not found';
}
```

## Disclaimer
I do not guarantee that this code is 100% secure and it should be used at your own responsibility.

If you find any errors or mistakes, open a ticket or create a pull request.

Please feel free to leave a comment and share your thoughts on this!
