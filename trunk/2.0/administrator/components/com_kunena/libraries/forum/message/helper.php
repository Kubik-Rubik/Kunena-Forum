<?php
/**
 * @version $Id$
 * Kunena Component
 * @package Kunena
 *
 * @Copyright (C) 2008 - 2011 Kunena Team. All rights reserved.
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL
 * @link http://www.kunena.org
 **/
defined ( '_JEXEC' ) or die ();

kimport ('kunena.error');
kimport ('kunena.forum.message');
kimport ('kunena.forum.topic.helper');

/**
 * Kunena Forum Message Helper Class
 */
class KunenaForumMessageHelper {
	// Global for every instance
	protected static $_instances = array();
	protected static $_location = false;

	private function __construct() {}

	/**
	 * Returns KunenaForumMessage object
	 *
	 * @access	public
	 * @param	identifier		The message to load - Can be only an integer.
	 * @return	KunenaForumMessage		The message object.
	 * @since	1.7
	 */
	static public function get($identifier = null, $reload = false) {
		if ($identifier instanceof KunenaForumMessage) {
			return $identifier;
		}
		$id = intval ( $identifier );
		if ($id < 1)
			return new KunenaForumMessage ();

		if ($reload || empty ( self::$_instances [$id] )) {
			self::$_instances [$id] = new KunenaForumMessage ( $id );
		}

		return self::$_instances [$id];
	}

	static public function getMessages($ids = false, $authorise='read') {
		if ($ids === false) {
			return self::$_instances;
		} elseif (is_array ($ids) ) {
			$ids = array_unique($ids);
		} else {
			$ids = array($ids);
		}
		self::loadMessages($ids);

		$list = array ();
		foreach ( $ids as $id ) {
			if (!empty(self::$_instances [$id]) && self::$_instances [$id]->authorise($authorise, null, true)) {
				$list [$id] = self::$_instances [$id];
			}
		}

		return $list;
	}

	static public function getMessagesByTopic($topic, $start=0, $limit=0, $ordering='ASC', $hold=0, $orderbyid = false) {
		$topic = KunenaForumTopicHelper::get($topic);
		if (!$topic->exists())
			return array();

		if ($start < 0)
			$start = 0;
		if ($limit < 1)
			$limit = KunenaFactory::getConfig()->messages_per_page;
		$ordering = strtoupper($ordering);
		if ($ordering != 'DESC')
			$ordering = 'ASC';

		return self::loadMessagesByTopic($topic->id, $start, $limit, $ordering, $hold, $orderbyid);
	}

	public function getLocation($mesid, $direction = 'asc', $hold=null) {
		if (!$hold) {
			$me = KunenaFactory::getUser();
			$access = KunenaFactory::getAccessControl();
			$hold = $access->getAllowedHold($me->userid, $this->id, false);
		}
		if (!isset(self::$_location [$mesid])) {
			self::loadLocation(array($mesid));
		}
		$location = self::$_location [$mesid];
		$count = 0;
		foreach ($location->hold as $meshold=>$values) {
			if (isset($hold[$meshold])) {
				$count += $values[$direction = 'asc' ? 'before' : 'after'];
				if ($direction == 'both') $count += $values['before'];
			}
		}
		return $count;
	}

	static function loadLocation($mesids) {
		// NOTE: if you already know the location using this code just takes resources
		if (!is_array($mesids)) $mesids = explode ( ',', $mesids );
		$list = array();
		$ids = array();
		foreach ($mesids as $id) {
			$id = (int) $id;
			if (!isset(self::$_location [$id])) {
				$ids[$id] = $id;
				self::$_location [$id] = new stdClass();
				self::$_location [$id]->hold = array();
			}
		}
		if (empty($ids))
			return;

		$idlist = implode ( ',', $ids );
		$db = JFactory::getDBO ();
		$db->setQuery ( "SELECT m.id, mm.hold, m.catid AS category_id, m.thread AS topic_id,
				SUM(mm.id<m.id) AS before_count,
				SUM(mm.id>m.id) AS after_count
			FROM #__kunena_messages AS m
			INNER JOIN #__kunena_messages AS mm ON m.thread=mm.thread
			WHERE m.id IN ({$idlist})
			GROUP BY m.id, mm.hold" );
		$results = (array) $db->loadObjectList ();
		KunenaError::checkDatabaseError();

		foreach ($results as $result) {
			$instance = self::$_location [$result->id];
			if (!isset($instance->id)) {
				$instance->id = $result->id;
				$instance->category_id = $result->category_id;
				$instance->topic_id = $result->topic_id;
				self::$_location [$instance->id] = $instance;
			}
			$instance->hold[$result->hold] = array('before'=>$result->before_count, 'after'=>$result->after_count);
		}
	}

	static function recount($topicids=false) {
		$db = JFactory::getDBO ();

		if (is_array($topicids)) {
			$where = 'WHERE m.thread IN ('.implode(',', $topicids).')';
		} elseif ((int)$topicids) {
			$where = 'WHERE m.thread='.(int)$topicids;
		} else {
			$where = '';
		}

		// Update catid in all messages
		$query ="UPDATE #__kunena_messages AS m
			INNER JOIN #__kunena_topics AS tt ON tt.id=m.thread
			SET m.catid=tt.category_id {$where}";
		$db->setQuery($query);
		$db->query ();
		if (KunenaError::checkDatabaseError ())
			return false;
		return $db->getAffectedRows ();
		}

	// Internal functions

	static protected function loadMessages($ids) {
		foreach ($ids as $i=>$id) {
			if (isset(self::$_instances [$id]))
				unset($ids[$i]);
		}
		if (empty($ids))
			return;

		$idlist = implode($ids);
		$db = JFactory::getDBO ();
		$query = "SELECT m.*, t.message FROM #__kunena_messages AS m INNER JOIN #__kunena_messages_text AS t ON m.id=t.mesid WHERE m.id IN ({$idlist})";
		$db->setQuery ( $query );
		$results = (array) $db->loadAssocList ('id');
		KunenaError::checkDatabaseError ();

		foreach ( $ids as $id ) {
			if (isset($results[$id])) {
				$instance = new KunenaForumMessage ();
				$instance->bind ( $results[$id] );
				$instance->exists(true);
				self::$_instances [$id] = $instance;
			} else {
				self::$_instances [$id] = null;
			}
		}
		unset ($results);
	}

	static protected function loadMessagesByTopic($topic_id, $start=0, $limit=0, $ordering='ASC', $hold=0, $orderbyid = false) {
		$db = JFactory::getDBO ();
		$query = "SELECT m.*, t.message
			FROM #__kunena_messages AS m
			INNER JOIN #__kunena_messages_text AS t ON m.id=t.mesid
			WHERE m.thread={$db->quote($topic_id)} AND m.hold IN ({$hold}) ORDER BY m.time {$ordering}";
		$db->setQuery ( $query, $start, $limit );
		$results = (array) $db->loadAssocList ('id');
		KunenaError::checkDatabaseError ();

		$list = array();
		foreach ( $results as $id=>$result ) {
			$instance = new KunenaForumMessage ();
			$instance->bind ( $result );
			$instance->exists(true);
			self::$_instances [$id] = $instance;
			$list[$orderbyid ? $id : $start++] = $instance;
		}
		unset ($results);
		return $list;
	}
}