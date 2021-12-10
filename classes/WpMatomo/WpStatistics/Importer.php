<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace WpMatomo\WpStatistics;

use Piwik\ArchiveProcessor\Parameters;
use Piwik\Common;
use Piwik\Container\StaticContainer;
use Piwik\DataAccess\ArchiveWriter;
use Piwik\Date;
use Piwik\Option;
use Piwik\Period\Factory;
use Piwik\Plugin\Manager;
use Piwik\Segment;
use Piwik\Site;
use Psr\Log\LoggerInterface;
use Piwik\Archive\ArchiveInvalidator;
use WpMatomo\WpStatistics\Exceptions\MaxEndDateReached;
class Importer {

	const IS_IMPORTED_FROM_GA_NUMERIC = 'GoogleAnalyticsImporter_isImportedFromGa';

	/**
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * @var array|null
	 */
	private $recordImporters;

	/**
	 * @var int
	 */
	private $queryCount = 0;

	/**
	 * @var string
	 */
	private $noDataMessageRemoved = false;

	/**
	 * Whether this is the main import date range or for a reimport range.
	 *
	 * @var bool
	 */
	private $isMainImport = true;

	private $endDate = null;

	public function __construct( LoggerInterface $logger ) {
		$this->logger  = $logger;
		$this->endDate = $this->getEndingDate();
	}

	public function setIsMainImport( $isMainImport ) {
		$this->isMainImport = $isMainImport;
	}

	/**
	 * Returns the first date in the matomo records
	 *
	 * @return Date
	 * @throws \Exception
	 */
	protected function getEndingDate() {
		global $wpdb;
		$db_settings  = new \WpMatomo\Db\Settings();
		$prefix_table = $db_settings->prefix_table_name( 'log_visit' );
		$sql          = <<<SQL
SELECT min(visit_last_action_time) from $prefix_table
SQL;
		$row          = $wpdb->get_row( $sql, ARRAY_N );
		return Date::factory( $row[0] );
	}

	/**
	 * Returns the first date in the wpStatistics data
	 *
	 * @return Piwik\Date
	 */
	protected function getStarted() {
		global $wpdb;
		$prefix_table = $wpdb->prefix . 'statistics_visit';
		$sql          = <<<SQL
SELECT min(last_visit) from $prefix_table
SQL;
		$row          = $wpdb->get_row( $sql, ARRAY_N );
		return Date::factory( $row[0] );
	}

	/**
	 * Update the first date in the configuration.
	 * Otherwise records are here but the date picker does not allow to select these dates
	 *
	 * @param int $idSite
	 * @param Date $date
	 *
	 * @return void
	 */
	private function adjustMatomoDate( $idSite, Date $date ) {
		global $wpdb;
		$db_settings  = new \WpMatomo\Db\Settings();
		$prefix_table = $db_settings->prefix_table_name( 'site' );
		$wpdb->update( $prefix_table, [ 'ts_created' => $date->toString( 'Y-m-d h:i:s' ) ], [ 'idsite' => $idSite ] );
	}
	public function import( $idSite ) {
		$date  = null;
		$end   = $this->endDate;
		$start = $this->getStarted();

		$this->adjustMatomoDate( $idSite, $start );
		try {
			$this->noDataMessageRemoved = false;
			$this->queryCount           = 0;

			$endPlusOne = $end->addDay( 1 );

			if ( $start->getTimestamp() >= $endPlusOne->getTimestamp() ) {
				throw new \InvalidArgumentException( "Invalid date range, start date is later than end date: {$start},{$end}" );
			}
			$recordImporters = $this->getRecordImporters( $idSite );
			$site            = new Site( $idSite );
			for ( $date = $start; $date->getTimestamp() < $endPlusOne->getTimestamp(); $date = $date->addDay( 1 ) ) {
				$this->logger->notice(
					'Importing data for date {date}...',
					[
						'date' => $date->toString(),
					]
				);

				try {
					$this->importDay( $site, $date, $recordImporters );
				} finally {
					// force delete all tables in case they aren't all freed
					\Piwik\DataTable\Manager::getInstance()->deleteAll();
				}
			}
			unset( $recordImporters );
		} catch ( MaxEndDateReached $ex ) {
			$this->logger->info( 'Max end date reached. This occurs in Matomo for WordPress installs when the importer tries to import days on or after the day Matomo for WordPress installed.' );
			return true;
		} catch ( \Exception $ex ) {
			$this->logger->debug( 'exception' );
			$this->onError( $idSite, $ex, $date );
			return true;
		}

		return false;
	}

	/**
	 * For use in RecordImporters that need to archive data for segments.
	 *
	 * @var RecordImporter[] $recordImporters
	 */
	public function importDay( Site $site, Date $date, $recordImporters, $plugin = null ) {
		if ( $this->endDate && $this->endDate->isEarlier( $date ) ) {
			throw new MaxEndDateReached();
		}
		$archiveWriter = $this->makeArchiveWriter( $site, $date, $plugin );
		$archiveWriter->initNewArchive();

		$recordInserter = new RecordInserter( $archiveWriter );

		foreach ( $recordImporters as $plugin => $recordImporter ) {
			if ( ! $recordImporter->supportsSite() ) {
				continue;
			}

			$this->logger->info(
				'Importing data for the {plugin} plugin.',
				[
					'plugin' => $plugin,
				]
			);

			$recordImporter->setRecordInserter( $recordInserter );

			$recordImporter->importRecords( $date );

			// since we recorded some data, at some time, remove the no data message
			if ( ! $this->noDataMessageRemoved ) {
				$this->removeNoDataMessage( $site->getId() );
				$this->noDataMessageRemoved = true;
			}

			// $this->currentLock->reexpireLock();
		}

		$archiveWriter->insertRecord( self::IS_IMPORTED_FROM_GA_NUMERIC, 1 );
		$archiveWriter->finalizeArchive();

		$invalidator                = StaticContainer::get( ArchiveInvalidator::class );
		$invalidator->markArchivesAsInvalidated(
			[ $site->getId() ],
			[ $date ],
			'week',
			null,
			false,
			false,
			null,
			$ignorePurgeLogDataDate = true
		);

		Common::destroy( $archiveWriter );
	}

	private function makeArchiveWriter( Site $site, Date $date, $segment = '', $plugin = null ) {
		$period  = Factory::build( 'day', $date );
		$segment = new Segment( $segment, [ $site->getId() ] );

		$params = new Parameters( $site, $period, $segment );
		if ( ! empty( $plugin ) ) {
			$params->setRequestedPlugin( $plugin );
		}
		return new ArchiveWriter( $params );
	}

	/**
	 * @param $idSite
	 * @param $viewId
	 * @return RecordImporter[]
	 * @throws \DI\NotFoundException
	 */
	private function getRecordImporters( $idSite ) {
		if ( empty( $this->recordImporters ) ) {
			$recordImporters = Config::getImporters();

			$this->recordImporters = [];
			foreach ( $recordImporters as $recordImporterClass ) {
				if ( ! defined( $recordImporterClass . '::PLUGIN_NAME' ) ) {
					throw new \Exception( "The $recordImporterClass record importer is missing the PLUGIN_NAME constant." );
				}

				$namespace  = explode( '\\', $recordImporterClass );
				$pluginName = array_pop( $namespace );
				if ( $this->isPluginUnavailable( $recordImporterClass::PLUGIN_NAME ) ) {
					continue;
				}

				$this->recordImporters[ $pluginName ] = $recordImporterClass;
			}
		}

		$instances = [];
		foreach ( $this->recordImporters as $pluginName => $className ) {
			$instances[ $pluginName ] = new $className( $this->logger );
		}
		return $instances;
	}

	private function removeNoDataMessage( $idSite ) {
		$hadTrafficKey = 'SitesManagerHadTrafficInPast_' . (int) $idSite;
		Option::set( $hadTrafficKey, 1 );
	}

	private function isPluginUnavailable( $pluginName ) {
		return ! Manager::getInstance()->isPluginActivated( $pluginName )
			|| ! Manager::getInstance()->isPluginLoaded( $pluginName )
			|| ! Manager::getInstance()->isPluginInFilesystem( $pluginName );
	}

	private function onError( $idSite, \Exception $ex, Date $date = null ) {
		$this->logger->info( 'Unexpected Error: {ex}', [ 'ex' => $ex ] );

		$dateStr = isset( $date ) ? $date->toString() : '(unknown)';
		// $this->importStatus->erroredImport($idSite, "Error on day $dateStr, " . $ex->getMessage());
	}
}
