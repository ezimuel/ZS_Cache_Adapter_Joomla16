<?php
/**
 * @package	Joomla.Framework
 * @subpackage	Cache
 * @author      Enrico Zimuel (enrico@zimuel.it)
 * @copyright	Copyright (C) 2010  Zimuel
 * @license	GNU General Public License version 2 or later; see LICENSE.txt
 */

// No direct access
defined('JPATH_BASE') or die;

/**
 * ZendServer cache storage handler
 *
 * @package		Joomla.Framework
 * @subpackage	Cache
 * @since		1.5
 */
class JCacheStorageZendservershm extends JCacheStorage
{
	/**
	 * Constructor
	 *
	 * @access protected
	 * @param array $options optional parameters
	 */
	public function __construct($options = array())
	{
		parent::__construct($options);
		// zendserver has no list keys, we do our own accounting, initalise key index
		$empty = array();
		zend_shm_cache_store($this->_hash.'-index',$empty);	
	}
	/**
	 * Get cached data from ZendServer by id and group
	 *
	 * @access	public
	 * @param	string	$id			The cache data id
	 * @param	string	$group		The cache data group
	 * @param	boolean	$checkTime	True to verify cache time expiration threshold
	 * @return	mixed	Boolean false on failure or a cached data string
	 * @since	1.5
	 */
	public function get($id, $group, $checkTime)
	{
		$cache_id = $this->_getCacheId($id, $group);
		return zend_shm_cache_fetch($cache_id);
	}
	/**
	 * Get all cached data
	 *
	 * @return	array data
	 * @since	1.6
	 */
	public function getAll()
	{
		parent::getAll();

		$keys = zend_shm_cache_fetch($this->_hash.'-index');
		$secret = $this->_hash;

		$data = array();

		if (!empty($keys)){
			foreach ($keys as $key) {
				if (empty($key)) {
					continue;
				}
				$namearr=explode('-',$key->name);

				if ($namearr !== false && $namearr[0]==$secret &&  $namearr[1]=='cache') {

					$group = $namearr[2];

					if (!isset($data[$group])) {
						$item = new JCacheStorageHelper($group);
					} else {
						$item = $data[$group];
					}

					$item->updateSize($key->size/1024);

					$data[$group] = $item;
				}
			}
		}

		return $data;
	}
	/**
	 * Store the data to ZendeServer chache by id and group
	 *
	 * @access	public
	 * @param	string	$id		The cache data id
	 * @param	string	$group	The cache data group
	 * @param	string	$data	The data to store in cache
	 * @return	boolean	True on success, false otherwise
	 * @since	1.5
	 */
	public function store($id, $group, $data)
	{
		$cache_id = $this->_getCacheId($id, $group);
		return zend_shm_cache_store($cache_id,$data,$this->_lifetime);	
	}
	/**
	 * Remove a cached data entry by id and group
	 *
	 * @access	public
	 * @param	string	$id		The cache data id
	 * @param	string	$group	The cache data group
	 * @return	boolean	True on success, false otherwise
	 * @since	1.5
	 */
	public function remove($id, $group)
	{
		$cache_id = $this->_getCacheId($id, $group);		
		return zend_shm_cache_delete($cache_id);
	}
	/**
	 * Clean cache for a group given a mode.
	 *
	 * group mode		: cleans all cache in the group
	 * notgroup mode	: cleans all cache not in the group
	 *
	 * @access	public
	 * @param	string	$group	The cache data group
	 * @param	string	$mode	The mode for cleaning cache [group|notgroup]
	 * @return	boolean	True on success, false otherwise
	 * @since	1.5
	 */
	public function clean($group, $mode)
	{
		$keys= $this->getAll();
		
		$secret = $this->_hash;

        if (is_array($keys)) {
        	foreach ($keys as $key) {

        		if (strpos($key['name'], $secret.'-cache-'.$group.'-')===0 xor $mode != 'group') {
					zend_shm_cache_delete($key['name']);
        		}
        	}
        }
		return true;
	}
	/**
	 * Garbage collect expired cache data
	 *
	 * @return boolean  True on success, false otherwise.
	 * @since	1.6
	 */
	public function gc() {
		// dummy, Zend Server has builtin garbage collector
		return true;
		
	}
	/**
	 * Test to see if the cache storage is available.
	 *
	 * @static
	 * @access public
	 * @return boolean  True on success, false otherwise.
	 */
	public static function test()
	{
		return (extension_loaded('Zend Data Cache') && ini_get('zend_datacache.enable'));
	}
	/**
	 * Lock cached item - override parent as this is more efficient
	 *
	 * @param	string	$id		The cache data id
	 * @param	string	$group	The cache data group
	 * @param	integer	$locktime Cached item max lock time
	 * @return	boolean	True on success, false otherwise.
	 * @since	1.6
	 */
	public function lock($id,$group,$locktime)
	{
		$returning = new stdClass();
		$returning->locklooped = false;

		$looptime = $locktime * 10;

		$cache_id = $this->_getCacheId($id, $group).'_lock';

		$data_lock = zend_shm_cache_store($cache_id,1,$locktime);

		if ( $data_lock === FALSE ) {

			$lock_counter = 0;

			// loop until you find that the lock has been released.  that implies that data get from other thread has finished
			while ( $data_lock === FALSE ) {

				if ( $lock_counter > $looptime ) {
					$returning->locked 		= false;
					$returning->locklooped 	= true;
					break;
				}

				usleep(100);
				$data_lock = zend_shm_cache_store($cache_id,1,$locktime);
				$lock_counter++;
			}

		}
		$returning->locked = $data_lock;

		return $returning;
	}
	/**
	 * Unlock cached item - override parent for cacheid compatibility with lock
	 *
	 * @param	string	$id		The cache data id
	 * @param	string	$group	The cache data group
	 * @param	integer	$locktime Cached item max lock time
	 * @return	boolean	True on success, false otherwise.
	 * @since	1.6
	 */
	public function unlock($id,$group=null)
	{
		$unlock = false;

		$cache_id = $this->_getCacheId($id, $group).'_lock';

		$unlock = zend_shm_cache_delete($cache_id);
		return $unlock;
	}
}
