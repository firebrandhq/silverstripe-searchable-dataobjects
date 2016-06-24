<?php

/**
 * Extension to provide a search interface when applied to ContentController
 *
 * Originally created by Gabriele Brosulo <gabriele.brosulo@zirak.it>
 *
 * @author Firebrand <developers@firebrand.nz>
 * @creation-date 04-June-2015
 */
class CustomSearch extends Extension {

	/**
	 * @var int the number of items for each page, used for pagination
	 */
	private static $items_per_page = 10;

	static $allowed_actions = array(
			'SearchForm',
			'results',
	);

	/**
	 * Site search form
	 */
	public function SearchForm() {

		$searchText = _t('SearchForm.SEARCH', 'Search');

		if ($this->owner->request && $this->owner->request->getVar('Search')) {
			$searchText = $this->owner->request->getVar('Search');
		}

		$fields = new FieldList(
						new TextField('Search', false, $searchText)
		);
		$actions = new FieldList(
						new FormAction('results', _t('SearchForm.GO', 'Go'))
		);
		$form = new SearchForm($this->owner, 'SearchForm', $fields, $actions);
		return $form;
	}

	/**
	 * Process and render search results.
	 *
	 * @param array $data The raw request data submitted by user
	 * @param SearchForm $form The form instance that was submitted
	 * @param SS_HTTPRequest $request Request generated for this action
	 */
	public function getSearchResults($request) {
	    
	    $getVars = $request->getVars();
        // set language (if present)
        if(class_exists('Translatable')) {
            if(singleton('SiteTree')->hasExtension('Translatable') && isset( $getVars['searchlocale'])) {
                if($getVars['searchlocale'] == "ALL") {
                    Translatable::disable_locale_filter();
                } else {
                    $origLocale = Translatable::get_current_locale();

                    Translatable::set_current_locale($getVars['searchlocale']);
                    $searchLocale = $getVars['searchlocale'];
                }
            }
        }

		$list = new ArrayList();

		$v = $request->getVars();
		$q = $v["Search"];

		$input = DB::getConn()->addslashes($q);
		$data = DB::query(<<<EOF
SELECT
	`pages`.`ID`,
	`pages`.`ClassName`,
	`pages`.`Title`,
	GROUP_CONCAT(`do`.`Content` SEPARATOR ' ') as `Content`,
	`pages`.`PageID`,
	SUM(MATCH (`do`.`Title`, `do`.`Content`) AGAINST ('$input' IN NATURAL LANGUAGE MODE)) as `relevance`
FROM
	SearchableDataObjects as `pages`
JOIN
	SearchableDataObjects as `do`
ON
	`pages`.`ID` = `do`.`OwnerID` AND
	`pages`.`ClassName` = `do`.`OwnerClassName`
WHERE
	`pages`.`ID` = `pages`.`OwnerID` AND
    `pages`.`ClassName` = `pages`.`OwnerClassName`
GROUP BY
	`pages`.`ID`,
	`pages`.`ClassName`
HAVING
	`relevance`
ORDER BY
	`relevance` DESC
EOF
		);

		foreach ($data as $row) {

			$do = DataObject::get_by_id($row['ClassName'], $row['ID']);

			if (!$do) {continue;}

			$do->Title = $row['Title'];
			$do->Content = $row['Content'];

			if(class_exists('Translatable') && isset($searchLocale)) {

                if(isset($do->Locale) && $do->Locale == $searchLocale) {
                    $list->push($do);
                }

            } else {
                $list->push($do);
            }
		}

		$pageLength = Config::inst()->get('CustomSearch', 'items_per_page');
		$ret = new PaginatedList($list, $request);
		$ret->setPageLength($pageLength);

		return $ret;
	}

	public function results($data, $form, $request) {

		$data = array(
				'Results' => $this->getSearchResults($request),
				'Query' => $form->getSearchQuery(),
				'Title' => _t('Search_results', 'Search Results')
		);
		return $this->owner->customise($data)->renderWith(array('Page_results', 'Page'));
	}

}
