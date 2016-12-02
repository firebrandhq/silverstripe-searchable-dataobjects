<?php

/**
 * Task manager to build searchable Data object table to do the initial index.
 *
 * Originally created by Gabriele Brosulo <gabriele.brosulo@zirak.it>
 *
 * @author Firebrand <developers@firebrand.nz>
 * @creation-date 04-June-2015
 */
class PopulateSearch extends BuildTask {
	
	/**
	 * DB initalization
	 */
	private function clearTable() {
		DB::query("DROP TABLE IF EXISTS SearchableDataObjects");
		DB::query("CREATE TABLE IF NOT EXISTS SearchableDataObjects (
													ID int(10) unsigned NOT NULL,
													ClassName varchar(255) NOT NULL,
													Title varchar(255) NOT NULL,
													Content text NOT NULL,
													PageID integer NOT NULL DEFAULT 0,
													OwnerID integer NOT NULL DEFAULT 0,
													OwnerClassName varchar(255) NOT NULL,
													PRIMARY KEY(ID, ClassName)
												) ENGINE=MyISAM");
		DB::query("ALTER TABLE SearchableDataObjects ADD FULLTEXT (`Title` ,`Content`)");
	}
	
	/**
	 * Refactor the DataObject in order to match with SearchableDataObjects table
	 * and insert it into the database
	 * @param DataObject $do
	 */
	public static function insert(DataObject $do) {
		// Title
		$Title = '';
		foreach($do->getTitleFields() as $field) {
			$Title .= Purifier::PurifyTXT($do->$field). ' ';
		}

		// Content
		$Content = '';
		foreach($do->getContentFields() as $field) {
			$Content .= Purifier::PurifyTXT($do->$field). ' ';
		}
		
		$owner = $do->getOwner();
		
		self::storeData($do->ID, $do->ClassName, trim($Title), trim($Content), $owner->ID, $owner->ClassName);
	}
	
	/**
	 * Clean page's title and content and insert it into SearchableDataObjects
	 * @param Page $p
	 */
	public static function insertPage(Page $p) {
		
		$Content = Purifier::PurifyTXT($p->Content);
		$Content = Purifier::RemoveEmbed($Content);

		self::storeData($p->ID, $p->ClassName, $p->Title, $Content, $p->ID, $p->ClassName);
	}

	/**
	 * Escape the data and store to the database
	 * @param $id
	 * @param $class_name
	 * @param $title
	 * @param $content
	 */

	private static function storeData($id, $class_name, $title, $content, $ownerID, $ownerClassName)
	{
		// prepare the query ...
		$query = sprintf(
			'INSERT INTO `SearchableDataObjects`
				(`ID`,  `ClassName`, `Title`, `Content`, `OwnerID`,  `OwnerClassName`)
			 VALUES
			 	(%1$d, \'%2$s\', \'%3$s\', \'%4$s\', %5$d, \'%6$s\')
			 ON DUPLICATE KEY
			 UPDATE
			 	Title=\'%3$s\',
			 	Content=\'%4$s\',
			 	OwnerID=%5$d,
			 	OwnerClassName=\'%6$s\'
			',
			intval($id),
			DB::getConn()->addslashes($class_name),
			DB::getConn()->addslashes($title),
			DB::getConn()->addslashes($content),
			intval($ownerID),
			DB::getConn()->addslashes($ownerClassName)
		);

		// run query ...
		DB::query($query);
	}


	/**
	 * Task run
	 * @param type $request
	 */
	public function run($request) {
		$this->clearTable();
				
		/*
		 * Page
		 */
		$pages = Versioned::get_by_stage('Page', 'Live')->filter(array('ShowInSearch' => 1));
		foreach ($pages as $p) {
			self::insertPage($p);
		}
		
		/*
		 * DataObjects
		 */		
		$searchables = ClassInfo::implementorsOf('Searchable');
		$linkables = ClassInfo::implementorsOf('SearchableLinkable');
		$searchables = array_unique(array_merge($linkables, $searchables));
		
		
		foreach ($searchables as $class) {
			// Filter
			$dos = $class::get()->filter($class::getSearchFilter());

            if (method_exists($class, 'getSearchFilterAny')) {
                $dos = $dos->filterAny($class::getSearchFilterAny());
            }
            if (method_exists($class, 'getSearchFilterByCallback')) {
                $dos = $dos->filterByCallback($class::getSearchFilterByCallback());
            }

			if($dos->exists()) {
			        $versionedCheck = $dos->first();
			
			        if($versionedCheck->hasExtension('Versioned')) {
			            $dos = Versioned::get_by_stage($class, 'Live')->filter($class::getSearchFilter());
			        }
	
				foreach ($dos as $do) {
					self::insert($do);
				}
			}
		}
		
	}
	
}
