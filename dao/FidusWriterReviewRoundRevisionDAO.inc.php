<?php
import('lib.pkp.classes.db.DAO');

class FidusWriterReviewRoundRevisionDAO extends DAO {
	/**
	 * @param $reviewRoundId
	 * @return DataObject|null
	 */
	function getRoundRevision($reviewRoundId)
	{
		$result = $this->retrieve(
			'SELECT * FROM ojs_fiduswriter_revisions WHERE review_round = ?',
			[(int) $reviewRoundId]
		);

		$row = (array) $result->current();
		return $row ? $this->_fromRow($row) : null;
	}

	/**
	 * @param $do DataObject
	 */
	function save($do)
	{
		$id = (int)$do->getData('ojs_fiduswriter_revision_id');

		if (empty($id)) {
			// Insert
			$this->update(
				'INSERT INTO ojs_fiduswriter_revisions (review_round, revision_url) VALUES (?, ?)',
				[
					(int)$do->getData('review_round'),
					$do->getData('revision_url')
				]
			);
		} else {
			// Update
			$this->update(
				'UPDATE	ojs_fiduswriter_revisions SET revision_url = ?, review_round = ? WHERE ojs_fiduswriter_revision_id = ?',
				array(
					$do->getData('revision_url'),
					$do->getData('review_round'),
					$id
				)
			);
		}
	}

	/**
	 * @param $row
	 * @return DataObject
	 */
	function _fromRow($row) {
		$do = new DataObject();
		$do->setData('ojs_fiduswriter_revision_id', (int)$row['ojs_fiduswriter_revision_id']);
		$do->setData('review_round', (int)$row['review_round']);
		$do->setData('revision_url', $row['revision_url']);

		return $do;
	}
}
