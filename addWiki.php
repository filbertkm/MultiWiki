<?php
/**
 * @defgroup Wikimedia Wikimedia
 */

/**
 * Add a new wiki
 * Wikimedia specific!
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Maintenance
 * @ingroup Wikimedia
 */
require_once( __DIR__ . '/WikimediaMaintenance.php' );

class AddWiki extends WikimediaMaintenance {
	public function __construct() {
		global $wgNoDBParam;

		parent::__construct();
		$this->mDescription = "Add a new wiki to the family. Wikimedia specific!";
		$this->addArg( 'language', 'Language code of new site, e.g. en' );
		$this->addArg( 'site', 'Type of site, e.g. wikipedia' );
		$this->addArg( 'dbname', 'Name of database to create, e.g. enwiki' );
		$this->addArg( 'domain', 'Domain name of the wiki, e.g. en.wikipedia.org' );

		$wgNoDBParam = true;
	}

	public function getDbType() {
		return Maintenance::DB_ADMIN;
	}

	public function execute() {
		global $IP, $wgDefaultExternalStore;

		$lang = $this->getArg( 0 );
		$site = $this->getArg( 1 );
		$dbName = $this->getArg( 2 );
		$domain = $this->getArg( 3 );
		$languageNames = Language::fetchLanguageNames();

		if ( !isset( $languageNames[$lang] ) ) {
			$this->error( "Language $lang not found in Names.php", true );
		}
		$name = $languageNames[$lang];

		$dbw = wfGetDB( DB_MASTER );
		$common = "/var/www/common";

		$this->output( "Creating database $dbName for $lang.$site ($name)\n" );

		# Set up the database
		# $dbw->query( "SET table_type=Innodb" );
		$dbw->query( "CREATE DATABASE $dbName" );
		$dbw->selectDB( $dbName );

		$this->output( "Initialising tables\n" );
		$dbw->sourceFile( $this->getDir() . '/tables.sql' );

/*		$dbw->sourceFile( "$IP/extensions/OAI/update_table.sql" );
		$dbw->sourceFile( "$IP/extensions/AntiSpoof/sql/patch-antispoof.mysql.sql" );
		$dbw->sourceFile( "$IP/extensions/CheckUser/cu_changes.sql" );
		$dbw->sourceFile( "$IP/extensions/CheckUser/cu_log.sql" );
		$dbw->sourceFile( "$IP/extensions/TitleKey/titlekey.sql" );
		$dbw->sourceFile( "$IP/extensions/Oversight/hidden.sql" );
		$dbw->sourceFile( "$IP/extensions/GlobalBlocking/globalblocking.sql" );
		$dbw->sourceFile( "$IP/extensions/AbuseFilter/abusefilter.tables.sql" );
		$dbw->sourceFile( "$IP/extensions/ClickTracking/patches/ClickTrackingEvents.sql" );
		$dbw->sourceFile( "$IP/extensions/ClickTracking/patches/ClickTracking.sql" );
		$dbw->sourceFile( "$IP/extensions/UserDailyContribs/patches/UserDailyContribs.sql" );
		$dbw->sourceFile( "$IP/extensions/Math/db/math.sql" );
		$dbw->sourceFile( "$IP/extensions/TimedMediaHandler/TimedMediaHandler.sql" );
		$dbw->sourceFile( "$IP/maintenance/archives/patch-filejournal.sql" );
*/
		// Add project specific extension table additions here
/*		switch ( $site ) {
			case 'wikipedia':
				break;
			case 'wiktionary':
				break;
			case 'wikiquote':
				break;
			case 'books':
				break;
			case 'wikinews':
				break;
			case 'wikisource':
				$dbw->sourceFile( "$IP/extensions/ProofreadPage/ProofreadPage.sql" );
				break;
			case 'wikiversity':
				break;
			case 'wikimedia':
				break;
			case 'wikidata':
				break;
			case 'wikivoyage':
				$dbw->sourceFile( "$IP/extensions/CreditsSource/schema/mysql/CreditsSource.sql" );
				break;
		}
*/
		$dbw->query( "INSERT INTO site_stats(ss_row_id) VALUES (1)" );

		# Initialise external storage
		if ( is_array( $wgDefaultExternalStore ) ) {
			$stores = $wgDefaultExternalStore;
		} elseif ( $wgDefaultExternalStore ) {
			$stores = array( $wgDefaultExternalStore );
		} else {
			$stores = array();
		}
		if ( count( $stores ) ) {
			global $wgDBuser, $wgDBpassword, $wgExternalServers;
			foreach ( $stores as $storeURL ) {
				$m = array();
				if ( !preg_match( '!^DB://(.*)$!', $storeURL, $m ) ) {
					continue;
				}

				$cluster = $m[1];
				var_export( $cluster );
				$this->output( "Initialising external storage $cluster...\n" );

				# Hack
				$wgExternalServers[$cluster][0]['user'] = $wgDBuser;
				$wgExternalServers[$cluster][0]['password'] = $wgDBpassword;

				$store = new ExternalStoreDB;
				$extdb = $store->getMaster( $cluster );
				#$extdb->query( "SET table_type=InnoDB" );
				$extdb->query( "CREATE DATABASE $dbName" );
				$extdb->selectDB( $dbName );

				# Hack x2
				$blobsTable = $store->getTable( $extdb );
				$sedCmd = "sed s/blobs\\\\\\>/$blobsTable/ " . $this->getDir() . "/storage/blobs.sql";
				$blobsFile = popen( $sedCmd, 'r' );
				$extdb->sourceStream( $blobsFile );
				pclose( $blobsFile );
				$extdb->commit();
			}
		}

		$title = Title::newFromText( wfMessage( 'mainpage' )->inLanguage( $lang )->useDatabase( false )->plain() );
		$this->output( "Writing main page to " . $title->getPrefixedDBkey() . "\n" );
		$article = WikiPage::factory( $title );
		$ucsite = ucfirst( $site );

		$article->doEdit( $this->getFirstArticle( $ucsite, $name ), '', EDIT_NEW | EDIT_AUTOSUMMARY );

		$time = wfTimestamp( TS_RFC2822 );
		// These arguments need to be escaped twice: once for echo and once for at
		$escDbName = wfEscapeShellArg( wfEscapeShellArg( $dbName ) );
		$escTime = wfEscapeShellArg( wfEscapeShellArg( $time ) );
		$escUcsite = wfEscapeShellArg( wfEscapeShellArg( $ucsite ) );
		$escName = wfEscapeShellArg( wfEscapeShellArg( $name ) );
		$escLang = wfEscapeShellArg( wfEscapeShellArg( $lang ) );
		$escDomain = wfEscapeShellArg( wfEscapeShellArg( $domain ) );
		shell_exec( "echo notifyNewProjects $escDbName $escTime $escUcsite $escName $escLang $escDomain | at now + 15 minutes" );

		$this->output( "Script ended. You still have to:
	* Add any required settings in InitialiseSettings.php
	* Run sync-common-all
"
		);
	}

	private function getFirstArticle( $ucsite, $name ) {
		return <<<EOT
==This subdomain is reserved for the creation of a [[wikimedia:Our projects|$ucsite]] in '''[[w:en:{$name}|{$name}]]''' language==

* Please '''do not start editing''' this new site. This site has a test project on the [[incubator:|Wikimedia Incubator]] (or on the [[betawikiversity:|Beta Wikiversity]] or on the [[oldwikisource:|Old Wikisource]]) and it will be imported to here.

* If you would like to help translating the interface to this language, please do not translate here, but go to [[translatewiki:|translatewiki.net]], a special wiki for translating the interface. That way everyone can use it on every wiki using the [[mw:|same software]].

* For information about how to edit and for other general help, see [[m:Help:Contents|Help on Wikimedia's Meta-Wiki]] or [[mw:Help:Contents|Help on MediaWiki.org]].

== Sister projects ==
<span class="plainlinks">
[http://www.wikipedia.org Wikipedia] |
[http://www.wiktionary.org Wiktionary] |
[http://www.wikibooks.org Wikibooks] |
[http://www.wikinews.org Wikinews] |
[http://www.wikiquote.org Wikiquote] |
[http://www.wikisource.org Wikisource] |
[http://www.wikiversity.org Wikiversity]
</span>

See Wikimedia's [[m:|Meta-Wiki]] for the coordination of these projects.

EOT;
	}
}

$maintClass = "AddWiki";
require_once( RUN_MAINTENANCE_IF_MAIN );
