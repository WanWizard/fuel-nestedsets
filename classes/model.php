<?php
/**
 * ExiteCMS
 *
 * ExiteCMS is web application framework, based on the Fuel PHP Framework
 *
 * @package    Themes
 * @version    1.0
 * @author     ExiteCMS Development Team
 * @license	   Creative Commons BY-NC-ND-3.0
 * @copyright  2011 ExiteCMS Development Team
 * @link       http://www.exitecms.org
 */

namespace Nestedsets;

/**
 * Model class.
 *
 * @package nestedsets
 */
class Model {

	/* ---------------------------------------------------------------------------
	 * Static usage
	 * --------------------------------------------------------------------------- */

	/*
	 * @var	default nestedset tree configuration
	 */
	protected static $defaults = array(
		'left_field'     => 'left_id',		// name of the tree node left index field
		'right_field'    => 'right_id',		// name of the tree node right index field
		'tree_field'     => null,			// name of the tree node tree index field
		'tree_value'     => null,			// value of the selected tree index
		'title_field'    => null,			// value of the tree node title field
		'symlink_field'  => 'symlink_id',	// name of the tree node tree index field
		'use_symlinks'   => false,			// use tree symlinks?
	);

	// -------------------------------------------------------------------------

	/*
	 * factory to produce nestedset objects
	 */
	public static function factory(\Orm\Model $model, Array $options = array())
	{
		return new static($model, $options);
	}

	/* ---------------------------------------------------------------------------
	 * Dynamic usage
	 * --------------------------------------------------------------------------- */

	/*
	 * ORM model object to work with
	 *
	 * @var    object	ORM\Model instance
	 */
	private $model = null;

	/**
	 * tree configuration for this instance
	 *
	 * @var    array
	 */
	private $configuration = array(
	);

	/**
	 * readonly fields for this model
	 *
	 * @var    array
	 */
	private $readonly_fields = array(
	);

	// -------------------------------------------------------------------------

	/*
	 * initialize the nestedset model instance
	 *
	 * @param    object	ORM\Model instance
	 * @param    array
	 */
	public function __construct(\Orm\Model $model, Array $properties)
	{
		// process the model's tree properties, set some defaults if needed
		if (isset($model::$tree) and is_array($model::$tree))
		{
			foreach(static::$defaults as $key => $value)
			{
				$this->configuration[$key] = isset($model::$tree[$key]) ? $model::$tree[$key] : static::$defaults[$key];
			}
		}
		else
		{
			$this->configuration = array_merge(static::$defaults, $this->configuration);
		}

		// override with custom properties if present
		foreach(static::$defaults as $key => $value)
		{
			isset($properties[$key]) and $this->configuration[$key] = $properties[$key];
		}

		// array of read-only column names
		foreach(array('left_field','right_field','tree_field','symlink_field') as $field)
		{
			! empty($this->configuration[$field]) and $this->readonly_fields[] = $this->configuration[$field];
		}

		$this->model = get_class($model);
	}

	/* -------------------------------------------------------------------------
	 * tree properties
	 * ---------------------------------------------------------------------- */

	/**
	 * Set a tree property
	 *
	 * @param  string
	 * @param  mixed
	 */
	protected function set_property($name, $value)
	{
		array_key_exists($name, $this->configuration) && $this->configuration[$name] = $value;
	}

	/**
	 * Get a tree property
	 *
	 * @param  string
	 * @param  mixed
	 */
	protected  function get_property($name)
	{
		return array_key_exists($name, $this->configuration) ? $this->configuration[$name] :  null;
	}

	/* -------------------------------------------------------------------------
	 * multi-tree select
	 * ---------------------------------------------------------------------- */

	/**
	 * Select a specific tree if the table contains multiple trees
	 *
	 * @param   mixed	type depends on the field type of the tree_field
	 * @return  object	this object, for chaining
	 */
	public function select($tree = null)
	{
		// set the filter value
		$this->set_property('tree_value', $tree);

		// return the object for chaining
		return $this;
	}

	/* -------------------------------------------------------------------------
	 * tree constructors
	 * ---------------------------------------------------------------------- */

	/**
	 * Creates a new root node
	 *
	 * @param   object ORM\Model
	 * @return	object	ORM\Model
	 */
	public function new_root(\Orm\Model $object)
	{
		$this->validate_model($object, __METHOD__);

		// we need a new object
		$object->is_new() or $object = clone($object);

		// set the left- and right pointers for the new root
		$object->{$this->configuration['left_field']} = 1;
		$object->{$this->configuration['right_field']} = 2;

		// multi-root tree?
		if ( ! is_null($this->configuration['tree_field']))
		{
			// insert the new object with a unique tree id
			$new_tree = $object->max($this->configuration['tree_field']) + 1;
			$max_errors = 5;
			while (true)
			{
				$object->{$this->configuration['tree_field']} = $new_tree++;

				// clumsy hack to hopefully capture a duplicate key error
				try
				{
					$object->save();
					break;
				}
				catch (\Exception $e)
				{
					// if we have more errors, it's likely not to be a duplicate key...
					if (--$max_errors == 0)
					{
						throw $e;
					}
				}
			}
		}
		else
		{
			$object->save();
		}

		// return the ORM Model object
		return $object;
	}

	/* -------------------------------------------------------------------------
	 * tree queries
	 * ---------------------------------------------------------------------- */

	/**
	 * Returns the root of the (selected) tree
	 *
	 * @return	object	ORM\Model
	 */
	public function get_root()
	{
		$query = call_user_func($this->model.'::find')->where($this->configuration['left_field'], 1);

		if ( ! is_null($this->configuration['tree_field']))
		{
			$query->where($this->configuration['tree_field'], $this->configuration['tree_value']);
		}


		// return the ORM Model object
		return $query->get_one();
	}

	// -----------------------------------------------------------------

	/**
	 * returns the parent of the 'object' passed
	 *
	 * @param   object ORM\Model
	 * @return	object	ORM\Model
	 */
	public function get_parent(\Orm\Model $object)
	{
		$this->validate_model($object, __METHOD__);

		$query = call_user_func($this->model.'::find');
		$query->where($this->configuration['left_field'], '<', $object->{$this->configuration['left_field']});
		$query->where($this->configuration['right_field'], '>', $object->{$this->configuration['right_field']});
		$query->order_by($this->configuration['right_field'], 'ASC');

		if ( ! is_null($this->configuration['tree_field']))
		{
			$query->where($this->configuration['tree_field'], $this->configuration['tree_value']);
		}

		// return the ORM Model object
		return $query->get_one();
	}

	// -----------------------------------------------------------------

	/**
	 * returns the first child of the 'object' passed
	 *
	 * @param   object ORM\Model
	 * @return	object	ORM\Model
	 */
	public function get_firstchild(\Orm\Model $object)
	{
		$this->validate_model($object, __METHOD__);

		$query = call_user_func($this->model.'::find');
		$query->where($this->configuration['left_field'], $object->{$this->configuration['left_field']} + 1);

		if ( ! is_null($this->configuration['tree_field']))
		{
			$query->where($this->configuration['tree_field'], $this->configuration['tree_value']);
		}

		// return the ORM Model object
		return $query->get_one();
	}

	// -----------------------------------------------------------------

	/**
	 * returns the last child of the 'object' passed
	 *
	 * @param   object ORM\Model
	 * @return	object	ORM\Model
	 */
	public function get_lastchild(\Orm\Model $object)
	{
		$this->validate_model($object, __METHOD__);

		$query = call_user_func($this->model.'::find');
		$query->where($this->configuration['right_field'], $object->{$this->configuration['right_field']} - 1);

		if ( ! is_null($this->configuration['tree_field']))
		{
			$query->where($this->configuration['tree_field'], $this->configuration['tree_value']);
		}

		// return the ORM Model object
		return $query->get_one();
	}

	// -----------------------------------------------------------------

	/**
	 * returns the previous sibling of the 'object' passed
	 *
	 * @param   object ORM\Model
	 * @return	object	ORM\Model
	 */
	public function get_previoussibling(\Orm\Model $object)
	{
		$this->validate_model($object, __METHOD__);

		$query = call_user_func($this->model.'::find');
		$query->where($this->configuration['right_field'], $object->{$this->configuration['left_field']} - 1);

		if ( ! is_null($this->configuration['tree_field']))
		{
			$query->where($this->configuration['tree_field'], $this->configuration['tree_value']);
		}

		// return the ORM Model object
		return $query->get_one();
	}

	// -----------------------------------------------------------------

	/**
	 * returns the next sibling of the 'object' passed
	 *
	 * @param   object ORM\Model
	 * @return	object	ORM\Model
	 */
	public function get_nextsibling(\Orm\Model $object)
	{
		$this->validate_model($object, __METHOD__);

		$query = call_user_func($this->model.'::find');
		$query->where($this->configuration['left_field'], $object->{$this->configuration['right_field']} + 1);

		if ( ! is_null($this->configuration['tree_field']))
		{
			$query->where($this->configuration['tree_field'], $this->configuration['tree_value']);
		}

		// return the ORM Model object
		return $query->get_one();
	}

	// -----------------------------------------------------------------
	// Boolean tree functions
	// -----------------------------------------------------------------

	/**
	 * Check if the object is a valid tree node
	 *
	 * @param   object ORM\Model
	 * @return  bool
	 */
	public function is_valid(\Orm\Model $object)
	{
		$this->validate_model($object, __METHOD__);

		if ( $object->is_new() )
		{
			return false;
		}
		elseif ( ! isset($object->{$this->configuration['left_field']}) or ! is_numeric($object->{$this->configuration['left_field']}) or $object->{$this->configuration['left_field']} <= 0 )
		{
			return false;
		}
		elseif ( ! isset($object->{$this->configuration['right_field']}) or ! is_numeric($object->{$this->configuration['right_field']}) or $object->{$this->configuration['right_field']} <= 0 )
		{
			return false;
		}
		elseif ( $object->{$this->configuration['left_field']} >= $object->{$this->configuration['right_field']} )
		{
			return false;
		}
		elseif ( ! is_null($this->configuration['tree_field']) and ! isset($object->{$this->configuration['tree_field']}) )
		{
			return false;
		}
		elseif ( ! is_null($this->configuration['tree_field']) and ( ! is_numeric($object->{$this->configuration['tree_field']}) or $object->{$this->configuration['tree_field']} <=0  ) )
		{
			return false;
		}

		// all looks well...
		return true;
	}

	// -----------------------------------------------------------------

	/**
	 * Check if the object is a tree root
	 *
	 * @param   object ORM\Model
	 * @return  bool
	 */
	public function is_root(\Orm\Model $object)
	{
		return $this->is_valid($object) and $object->{$this->configuration['left_field']} == 1;
	}

	// -----------------------------------------------------------------

	/**
	 * check if the object is a tree leaf (node with no children)
	 *
	 * @param   object ORM\Model
	 * @return  bool
	 */
	public function is_leaf(\Orm\Model $object)
	{
		return $this->is_valid($object) and $object->{$this->configuration['right_field']} - $object->{$this->configuration['left_field']} == 1;
	}

	// -----------------------------------------------------------------

	/**
	 * check if the object is a child node (not a root node)
	 *
	 * @param   object ORM\Model
	 * @return  bool
	 */
	public function is_child(\Orm\Model $object)
	{
		return $this->is_valid($object) and ! $this->is_root($object);
	}

	// -----------------------------------------------------------------

	/**
	 * check if the object is a child of node
	 *
	 * @param   object ORM\Model
	 * @return  bool
	 */
	public function is_child_of(\Orm\Model $child, \Orm\Model $parent)
	{
		return $this->is_valid($child) and
			$this->is_valid($parent) and
			$child->{$this->configuration['left_field']} > $parent->{$this->configuration['left_field']} and
			$child->{$this->configuration['right_field']} < $parent->{$this->configuration['right_field']};
	}

	// -----------------------------------------------------------------

	/**
	 * check if the object is a parent of node
	 *
	 * @param   object ORM\Model
	 * @return  bool
	 */
	public function is_parent_of(\Orm\Model $parent, \Orm\Model $child)
	{
		return $this->is_valid($child) and
			$this->is_valid($parent) and
			$child->{$this->configuration['left_field']} > $parent->{$this->configuration['left_field']} and
			$child->{$this->configuration['right_field']} < $parent->{$this->configuration['right_field']};
	}

	// -----------------------------------------------------------------

	/**
	 * check if the object has a parent
	 *
	 * Note: this is an alias for is_child()
	 *
	 * @param   object ORM\Model
	 * @return  bool
	 */
	public function has_parent(\Orm\Model $child)
	{
		return $this->is_child($child);
	}

	// -----------------------------------------------------------------

	/**
	 * check if the object has children
	 *
	 * @param   object ORM\Model
	 * @return  bool
	 */
	public function has_children(\Orm\Model $parent)
	{
		return $this->is_leaf($parent) ? false : true;
	}

	// -----------------------------------------------------------------

	/**
	 * Check if the object has a previous sibling
	 *
	 * @param   object ORM\Model
	 * @return  bool
	 */
	public function has_previoussibling(\Orm\Model $object)
	{
		return ! is_null($this->get_previoussibling($object));
	}

	// -----------------------------------------------------------------

	/**
	 * Check if the object has a next sibling
	 *
	 * @param   object ORM\Model
	 * @return  bool
	 */
	public function has_nextsibling(\Orm\Model $object)
	{
		return ! is_null($this->get_nextsibling($object));
	}

	// -----------------------------------------------------------------
	// Integer tree functions
	// -----------------------------------------------------------------

	/**
	 * return the count of the objects children
	 *
	 * @param   object ORM\Model
	 * @return	mixed	integer, of false in case no valid object was passed
	 */
	public function count_children(\Orm\Model $object)
	{
		$this->validate_model($object, __METHOD__);

		return $this->is_valid($object) ? (($object->{$this->configuration['right_field']} - $object->{$this->configuration['left_field']} - 1) / 2) : false;
	}

	// -----------------------------------------------------------------

	/**
	 * return the depth of the object in the tree, where the root = 0
	 *
	 * @param   object ORM\Model
	 * @return	mixed	integer, of false in case no valid object was passed
	 */
	public function depth(\Orm\Model $object)
	{
		$this->validate_model($object, __METHOD__);

		if ($this->is_valid($object))
		{
			$query = call_user_func($this->model.'::find');

			if ( ! is_null($this->configuration['tree_field']))
			{
				$query->where($this->configuration['tree_field'], $this->configuration['tree_value']);
			}

			$query->where($this->configuration['left_field'], '<', $object->{$this->configuration['left_field']});
			$query->where($this->configuration['right_field'], '>', $object->{$this->configuration['right_field']});

			// return the ORM Model result count
			return $query->count();
		}
		else
		{
			return false;
		}
	}

	/* -------------------------------------------------------------------------
	 * tree reorganisation functions
	 * ---------------------------------------------------------------------- */

	/**
	 * move $object to next silbling of $to
	 *
	 * @param   object ORM\Model
	 * @param   object ORM\Model
	 * @return  bool
	 */
	public function make_nextsibling_of(\Orm\Model $object, \Orm\Model $to)
	{
		$this->validate_model($object, __METHOD__);
		$this->validate_model($to, __METHOD__);

		if ($this->is_valid($object) and $this->is_valid($to))
		{
			return $this->move_subtree($object, $to->{$this->configuration['right_field']} + 1);
		}
		else
		{
			return false;
		}
	}

	// -----------------------------------------------------------------

	/**
	 * move $object to previous silbling of $to
	 *
	 * @param   object ORM\Model
	 * @param   object ORM\Model
	 * @return  bool
	 */
	public function make_previoussibling_of(\Orm\Model $object, \Orm\Model $to)
	{
		$this->validate_model($object, __METHOD__);
		$this->validate_model($to, __METHOD__);

		if ($this->is_valid($object) and $this->is_valid($to))
		{
			return $this->move_subtree($object, $to->{$this->configuration['left_field']});
		}
		else
		{
			return false;
		}
	}

	// -----------------------------------------------------------------

	/**
	 * move $object to first child of $to
	 *
	 * @param   object ORM\Model
	 * @param   object ORM\Model
	 * @return  bool
	 */
	public function make_firstchild_of(\Orm\Model $object, \Orm\Model $to)
	{
		$this->validate_model($object, __METHOD__);
		$this->validate_model($to, __METHOD__);

		if ($this->is_valid($object) and $this->is_valid($to))
		{
			return $this->move_subtree($object, $to->{$this->configuration['left_field']} + 1);
		}
		else
		{
			return false;
		}
	}

	// -----------------------------------------------------------------

	/**
	 * move $object to last child of $to
	 *
	 * @param   object ORM\Model
	 * @param   object ORM\Model
	 * @return  bool
	 */
	public function make_lastchild_of(\Orm\Model $object, \Orm\Model $to)
	{
		$this->validate_model($object, __METHOD__);
		$this->validate_model($to, __METHOD__);

		if ($this->is_valid($object) and $this->is_valid($to))
		{
			return $this->move_subtree($object, $to->{$this->configuration['right_field']});
		}
		else
		{
			return false;
		}
	}

	/* -------------------------------------------------------------------------
	 * tree destructors
	 * ---------------------------------------------------------------------- */

	/**
	 * deletes the entire tree structure including all records
	 *
	 * @param	mixed	id of the tree to delete, or a valid ORM\Model
	 * @return	bool
	 */
	public function delete_tree($param = null)
	{
		$query = call_user_func($this->model.'::find');

		// if we have multiple roots
		if ( ! is_null($this->configuration['tree_field']))
		{
			// is the parameter an ORM object?
			if ($param instanceOf \Orm\Model)
			{
				$this->validate($param);

				if ($this->is_valid($param))
				{
					$param->freeze();
					$query->where($this->configuration['tree_field'], $param->{$this->configuration['tree_field']});
				}
			}
			elseif (is_numeric($param))
			{
				$query->where($this->configuration['tree_field'], $param);
			}
		}

		return $query->delete();
	}

	// -----------------------------------------------------------------

	/**
	 * deletes the entire tree structure including all records
	 *
	 * @param	object	ORM\Model
	 * @return	bool
	 */
	public function delete_node(\Orm\Model $object)
	{
		$this->validate_model($object, __METHOD__);

		if ($this->valid($object))
		{
			// delete the node
			$object->delete();

			// re-index the tree
			$this->shift_rlvalues($object, $object->{$this->configuration['right_field']} + 1, $object->{$this->configuration['left_field']} - $object->{$this->configuration['right_field']} - 1);
		}
		else
		{
			return false;
		}

		return true;
	}

	/* -------------------------------------------------------------------------
	 * tree dump functions
	 * ---------------------------------------------------------------------- */

	/**
	 * Returns the tree in a key-value format suitable for html dropdowns
	 *
	 * @param   string
	 * @param   boolean
	 * @return	array
	 */
	public function dump_dropdown($field = false, $skip_root = false)
	{
		/* TODO */ die('Model_Trees: tree_dump_dropdown()');
	}

	// -----------------------------------------------------------------

	/**
	 * Dumps the entire tree in HTML or TAB formatted output
	 *
	 * @param	string	type of output requested, possible values 'html', 'tab', 'csv', 'array' ('array' = default)
	 * @param	array	list of columns to include in the dump
	 * @param	boolean	if true, the object itself (root of the dump) will not be included
	 * @return	mixed
	 */
	public function dump_as($type = 'array', $attributes = null, $skip_root = true)
	{
		/* TODO */ die('Model_Trees: tree_dump_as()');
	}

	/* -------------------------------------------------------------------------
	 * private class functions
	 * ---------------------------------------------------------------------- */

	private function validate_model($object, $method)
	{
		if (get_class($object) !== $this->model)
		{
			throw new \Exception('Model object passed to '.$method.'() is not an instance of '.$this->model.'.');
		}
	}

	// -----------------------------------------------------------------

	private function shift_rlvalues($object, $first, $delta)
	{
		/* TODO */ die('Model_Trees: shift_rlvalues()');
	}

	// -----------------------------------------------------------------

	private function shift_rlrange($object, $first, $last, $delta)
	{
		/* TODO */ die('Model_Trees: shift_rlrange()');
	}

	// -----------------------------------------------------------------

	private function move_subtree($object, $left_id)
	{
		/* TODO */ die('Model_Trees: move_subtree()');
	}
}

/* End of file model.php */
