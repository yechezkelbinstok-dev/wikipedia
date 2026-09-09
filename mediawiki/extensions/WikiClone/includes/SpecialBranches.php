<?php

namespace MediaWiki\Extension\WikiClone;

use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;

/**
 * List the branches, and make new ones.
 *
 * Switching between them happens from the page tabs; this page exists for the
 * two things that do not fit there — seeing what exists, and creating one.
 */
class SpecialBranches extends SpecialPage {

	private BranchStore $branches;

	public function __construct( BranchStore $branches ) {
		parent::__construct( 'Branches' );
		$this->branches = $branches;
	}

	/** @inheritDoc */
	public function execute( $subPage ) {
		$this->setHeaders();
		$this->outputHeader();
		$out = $this->getOutput();

		$request = $this->getRequest();
		if ( $request->wasPosted() && $this->getUser()->isRegistered() ) {
			$this->handleCreate();
		}

		$out->addHTML( $this->branchTable() );

		if ( $this->getUser()->isRegistered() ) {
			$out->addHTML( $this->createForm() );
		}
	}

	private function handleCreate(): void {
		$request = $this->getRequest();
		$out = $this->getOutput();

		if ( !$this->getUser()->matchEditToken( $request->getVal( 'wpEditToken' ) ) ) {
			$out->addHTML( Html::errorBox( $this->msg( 'sessionfailure' )->text() ) );
			return;
		}

		$name = trim( $request->getText( 'wpBranchName' ) );
		if ( $name === '' || !preg_match( '/^[A-Za-z0-9 _.-]{1,64}$/', $name ) ) {
			$out->addHTML( Html::errorBox( $this->msg( 'wikiclone-branch-badname' )->text() ) );
			return;
		}

		if ( strcasecmp( $name, BranchStore::LIVE_NAME ) === 0 || $this->branches->getByName( $name ) ) {
			$out->addHTML( Html::errorBox( $this->msg( 'wikiclone-branch-exists', $name )->text() ) );
			return;
		}

		$from = (int)$request->getInt( 'wpBranchFrom' );
		if ( $from !== BranchStore::LIVE && !$this->branches->getById( $from ) ) {
			$from = BranchStore::LIVE;
		}

		$this->branches->create( $name, $from, $this->getUser() );

		$out->addHTML( Html::successBox( $this->msg( 'wikiclone-branch-created', $name )->text() ) );
	}

	private function branchTable(): string {
		$rows = Html::rawElement( 'tr', [],
			Html::element( 'th', [], $this->msg( 'wikiclone-branch-name' )->text() )
			. Html::element( 'th', [], $this->msg( 'wikiclone-branch-forkedfrom' )->text() )
		);

		$rows .= Html::rawElement( 'tr', [],
			Html::rawElement( 'td', [], $this->switchLink( BranchStore::LIVE, BranchStore::LIVE_NAME ) )
			. Html::element( 'td', [], '—' )
		);

		foreach ( $this->branches->listBranches() as $branch ) {
			$rows .= Html::rawElement( 'tr', [],
				Html::rawElement( 'td', [], $this->switchLink( $branch['id'], $branch['name'] ) )
				. Html::element( 'td', [], $this->branches->nameOf( $branch['from'] ) )
			);
		}

		return Html::rawElement( 'table', [ 'class' => 'wikitable' ], $rows );
	}

	private function switchLink( int $id, string $name ): string {
		return Html::element(
			'a',
			[ 'href' => Title::newMainPage()->getLocalURL( [ 'branch' => $name ] ) ],
			$name
		);
	}

	private function createForm(): string {
		$options = Html::element(
			'option',
			[ 'value' => BranchStore::LIVE ],
			BranchStore::LIVE_NAME
		);
		foreach ( $this->branches->listBranches() as $branch ) {
			$options .= Html::element( 'option', [ 'value' => $branch['id'] ], $branch['name'] );
		}

		return Html::rawElement( 'form',
			[ 'method' => 'post', 'action' => $this->getPageTitle()->getLocalURL() ],
			Html::element( 'h2', [], $this->msg( 'wikiclone-branch-create' )->text() )
			. Html::element( 'label', [ 'for' => 'wpBranchName' ],
				$this->msg( 'wikiclone-branch-name' )->text() ) . ' '
			. Html::element( 'input', [
				'type' => 'text', 'name' => 'wpBranchName', 'id' => 'wpBranchName', 'maxlength' => 64,
			] ) . ' '
			. Html::element( 'label', [ 'for' => 'wpBranchFrom' ],
				$this->msg( 'wikiclone-branch-forkedfrom' )->text() ) . ' '
			. Html::rawElement( 'select',
				[ 'name' => 'wpBranchFrom', 'id' => 'wpBranchFrom' ], $options ) . ' '
			. Html::element( 'input', [
				'type' => 'hidden',
				'name' => 'wpEditToken',
				'value' => $this->getUser()->getEditToken(),
			] )
			. Html::element( 'button', [ 'type' => 'submit' ],
				$this->msg( 'wikiclone-branch-create' )->text() )
		);
	}

	/** @inheritDoc */
	protected function getGroupName() {
		return 'pagetools';
	}
}
