<?php
/**
 * WC_Admin_Reports_Customers_Data_Store class file.
 *
 * @package WooCommerce Admin/Classes
 */

defined( 'ABSPATH' ) || exit;

/**
 * WC_Admin_Reports_Customers_Data_Store.
 */
class WC_Admin_Reports_Customers_Data_Store extends WC_Admin_Reports_Data_Store implements WC_Admin_Reports_Data_Store_Interface {

	/**
	 * Table used to get the data.
	 *
	 * @var string
	 */
	const TABLE_NAME = 'wc_customer_lookup';

	/**
	 * Mapping columns to data type to return correct response types.
	 *
	 * @var array
	 */
	protected $column_types = array(
		'customer_id'     => 'intval',
		'user_id'         => 'intval',
		'orders_count'    => 'intval',
		'total_spend'     => 'floatval',
		'avg_order_value' => 'floatval',
	);

	/**
	 * SQL columns to select in the db query and their mapping to SQL code.
	 *
	 * @var array
	 */
	protected $report_columns = array(
		'customer_id'      => 'customer_id',
		'user_id'          => 'user_id',
		'username'         => 'username',
		'name'             => "CONCAT_WS( ' ', first_name, last_name ) as name", // @todo: what does this mean for RTL?
		'email'            => 'email',
		'country'          => 'country',
		'city'             => 'city',
		'postcode'         => 'postcode',
		'date_registered'  => 'date_registered',
		'date_last_active' => 'date_last_active',
		'orders_count'     => 'COUNT( order_id ) as orders_count',
		'total_spend'      => 'SUM( gross_total ) as total_spend',
		'avg_order_value'  => '( SUM( gross_total ) / COUNT( order_id ) ) as avg_order_value',
	);

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;

		// Initialize some report columns that need disambiguation.
		$this->report_columns['customer_id']     = $wpdb->prefix . self::TABLE_NAME . '.customer_id';
		$this->report_columns['date_last_order'] = "MAX( {$wpdb->prefix}wc_order_stats.date_created ) as date_last_order";
	}

	/**
	 * Set up all the hooks for maintaining and populating table data.
	 */
	public static function init() {
		add_action( 'woocommerce_new_customer', array( __CLASS__, 'update_registered_customer' ) );
		add_action( 'woocommerce_update_customer', array( __CLASS__, 'update_registered_customer' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'update_registered_customer' ) );
		add_action( 'updated_user_meta', array( __CLASS__, 'update_registered_customer_via_last_active' ), 10, 3 );
	}

	/**
	 * Trigger a customer update if their "last active" meta value was changed.
	 * Function expects to be hooked into the `updated_user_meta` action.
	 *
	 * @param int    $meta_id ID of updated metadata entry.
	 * @param int    $user_id ID of the user being updated.
	 * @param string $meta_key Meta key being updated.
	 */
	public static function update_registered_customer_via_last_active( $meta_id, $user_id, $meta_key ) {
		if ( 'wc_last_active' === $meta_key ) {
			self::update_registered_customer( $user_id );
		}
	}

	/**
	 * Maps ordering specified by the user to columns in the database/fields in the data.
	 *
	 * @param string $order_by Sorting criterion.
	 * @return string
	 */
	protected function normalize_order_by( $order_by ) {
		if ( 'name' === $order_by ) {
			return "CONCAT_WS( ' ', first_name, last_name )";
		}

		return $order_by;
	}

	/**
	 * Fills ORDER BY clause of SQL request based on user supplied parameters.
	 *
	 * @param array $query_args Parameters supplied by the user.
	 * @return array
	 */
	protected function get_order_by_sql_params( $query_args ) {
		$sql_query['order_by_clause'] = '';

		if ( isset( $query_args['orderby'] ) ) {
			$sql_query['order_by_clause'] = $this->normalize_order_by( $query_args['orderby'] );
		}

		if ( isset( $query_args['order'] ) ) {
			$sql_query['order_by_clause'] .= ' ' . $query_args['order'];
		} else {
			$sql_query['order_by_clause'] .= ' DESC';
		}

		return $sql_query;
	}

	/**
	 * Fills WHERE clause of SQL request with date-related constraints.
	 *
	 * @param array  $query_args Parameters supplied by the user.
	 * @param string $table_name Name of the db table relevant for the date constraint.
	 * @return array
	 */
	protected function get_time_period_sql_params( $query_args, $table_name ) {
		global $wpdb;

		$sql_query           = array(
			'where_time_clause' => '',
			'where_clause'      => '',
			'having_clause'     => '',
		);
		$date_param_mapping  = array(
			'registered'  => array(
				'clause' => 'where',
				'column' => $table_name . '.date_registered',
			),
			'last_active' => array(
				'clause' => 'where',
				'column' => $table_name . '.date_last_active',
			),
			'last_order'  => array(
				'clause' => 'having',
				'column' => "MAX( {$wpdb->prefix}wc_order_stats.date_created )",
			),
		);
		$match_operator      = $this->get_match_operator( $query_args );
		$where_time_clauses  = array();
		$having_time_clauses = array();

		foreach ( $date_param_mapping as $query_param => $param_info ) {
			$subclauses  = array();
			$before_arg  = $query_param . '_before';
			$after_arg   = $query_param . '_after';
			$column_name = $param_info['column'];

			if ( ! empty( $query_args[ $before_arg ] ) ) {
				$datetime     = new DateTime( $query_args[ $before_arg ] );
				$datetime_str = $datetime->format( WC_Admin_Reports_Interval::$sql_datetime_format );
				$subclauses[] = "{$column_name} <= '$datetime_str'";
			}

			if ( ! empty( $query_args[ $after_arg ] ) ) {
				$datetime     = new DateTime( $query_args[ $after_arg ] );
				$datetime_str = $datetime->format( WC_Admin_Reports_Interval::$sql_datetime_format );
				$subclauses[] = "{$column_name} >= '$datetime_str'";
			}

			if ( $subclauses && ( 'where' === $param_info['clause'] ) ) {
				$where_time_clauses[] = '(' . implode( ' AND ', $subclauses ) . ')';
			}

			if ( $subclauses && ( 'having' === $param_info['clause'] ) ) {
				$having_time_clauses[] = '(' . implode( ' AND ', $subclauses ) . ')';
			}
		}

		if ( $where_time_clauses ) {
			$sql_query['where_time_clause'] = ' AND ' . implode( " {$match_operator} ", $where_time_clauses );
		}

		if ( $having_time_clauses ) {
			$sql_query['having_clause'] = ' AND ' . implode( " {$match_operator} ", $having_time_clauses );
		}

		return $sql_query;
	}

	/**
	 * Updates the database query with parameters used for Customers report: categories and order status.
	 *
	 * @param array $query_args Query arguments supplied by the user.
	 * @return array            Array of parameters used for SQL query.
	 */
	protected function get_sql_query_params( $query_args ) {
		global $wpdb;
		$customer_lookup_table  = $wpdb->prefix . self::TABLE_NAME;
		$order_stats_table_name = $wpdb->prefix . 'wc_order_stats';

		$sql_query_params                = $this->get_time_period_sql_params( $query_args, $customer_lookup_table );
		$sql_query_params                = array_merge( $sql_query_params, $this->get_limit_sql_params( $query_args ) );
		$sql_query_params                = array_merge( $sql_query_params, $this->get_order_by_sql_params( $query_args ) );
		$sql_query_params['from_clause'] = " LEFT JOIN {$order_stats_table_name} ON {$customer_lookup_table}.customer_id = {$order_stats_table_name}.customer_id";

		$match_operator = $this->get_match_operator( $query_args );
		$where_clauses  = array();
		$having_clauses = array();

		$exact_match_params = array(
			'username',
			'email',
			'country',
		);

		foreach ( $exact_match_params as $exact_match_param ) {
			if ( ! empty( $query_args[ $exact_match_param ] ) ) {
				$where_clauses[] = $wpdb->prepare(
					"{$customer_lookup_table}.{$exact_match_param} = %s",
					$query_args[ $exact_match_param ]
				); // WPCS: unprepared SQL ok.
			}
		}

		if ( ! empty( $query_args['name'] ) ) {
			$where_clauses[] = $wpdb->prepare( "CONCAT_WS( ' ', first_name, last_name ) = %s", $query_args['name'] );
		}

		$numeric_params = array(
			'orders_count'    => array(
				'column' => 'COUNT( order_id )',
				'format' => '%d',
			),
			'total_spend'     => array(
				'column' => 'SUM( gross_total )',
				'format' => '%f',
			),
			'avg_order_value' => array(
				'column' => '( SUM( gross_total ) / COUNT( order_id ) )',
				'format' => '%f',
			),
		);

		foreach ( $numeric_params as $numeric_param => $param_info ) {
			$subclauses = array();
			$min_param  = $numeric_param . '_min';
			$max_param  = $numeric_param . '_max';

			if ( isset( $query_args[ $min_param ] ) ) {
				$subclauses[] = $wpdb->prepare(
					"{$param_info['column']} >= {$param_info['format']}",
					$query_args[ $min_param ]
				); // WPCS: unprepared SQL ok.
			}

			if ( isset( $query_args[ $max_param ] ) ) {
				$subclauses[] = $wpdb->prepare(
					"{$param_info['column']} <= {$param_info['format']}",
					$query_args[ $max_param ]
				); // WPCS: unprepared SQL ok.
			}

			if ( $subclauses ) {
				$having_clauses[] = '(' . implode( ' AND ', $subclauses ) . ')';
			}
		}

		if ( $where_clauses ) {
			$preceding_match                  = empty( $sql_query_params['where_time_clause'] ) ? ' AND ' : " {$match_operator} ";
			$sql_query_params['where_clause'] = $preceding_match . implode( " {$match_operator} ", $where_clauses );
		}

		$order_status_filter = $this->get_status_subquery( $query_args );
		if ( $order_status_filter ) {
			$sql_query_params['from_clause'] .= " AND ( {$order_status_filter} )";
		}

		if ( $having_clauses ) {
			$preceding_match                    = empty( $sql_query_params['having_clause'] ) ? ' AND ' : " {$match_operator} ";
			$sql_query_params['having_clause'] .= $preceding_match . implode( " {$match_operator} ", $having_clauses );
		}

		return $sql_query_params;
	}

	/**
	 * Returns the report data based on parameters supplied by the user.
	 *
	 * @param array $query_args  Query parameters.
	 * @return stdClass|WP_Error Data.
	 */
	public function get_data( $query_args ) {
		global $wpdb;

		$customers_table_name   = $wpdb->prefix . self::TABLE_NAME;
		$order_stats_table_name = $wpdb->prefix . 'wc_order_stats';

		// These defaults are only partially applied when used via REST API, as that has its own defaults.
		$defaults   = array(
			'per_page' => get_option( 'posts_per_page' ),
			'page'     => 1,
			'order'    => 'DESC',
			'orderby'  => 'date_registered',
			'fields'   => '*',
		);
		$query_args = wp_parse_args( $query_args, $defaults );

		$cache_key = $this->get_cache_key( $query_args );
		$data      = wp_cache_get( $cache_key, $this->cache_group );

		if ( false === $data ) {
			$data = (object) array(
				'data'    => array(),
				'total'   => 0,
				'pages'   => 0,
				'page_no' => 0,
			);

			$selections       = $this->selected_columns( $query_args );
			$sql_query_params = $this->get_sql_query_params( $query_args );

			$db_records_count = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM (
					SELECT {$customers_table_name}.customer_id
					FROM
						{$customers_table_name}
						{$sql_query_params['from_clause']}
					WHERE
						1=1
						{$sql_query_params['where_time_clause']}
						{$sql_query_params['where_clause']}
					GROUP BY
						{$customers_table_name}.customer_id
					HAVING
						1=1
						{$sql_query_params['having_clause']}
				) as tt
				"
			); // WPCS: cache ok, DB call ok, unprepared SQL ok.

			$total_pages = (int) ceil( $db_records_count / $sql_query_params['per_page'] );
			if ( $query_args['page'] < 1 || $query_args['page'] > $total_pages ) {
				return $data;
			}

			$customer_data = $wpdb->get_results(
				"SELECT
						{$selections}
					FROM
						{$customers_table_name}
						{$sql_query_params['from_clause']}
					WHERE
						1=1
						{$sql_query_params['where_time_clause']}
						{$sql_query_params['where_clause']}
					GROUP BY
						{$customers_table_name}.customer_id
					HAVING
						1=1
						{$sql_query_params['having_clause']}
					ORDER BY
						{$sql_query_params['order_by_clause']}
					{$sql_query_params['limit']}
					",
				ARRAY_A
			); // WPCS: cache ok, DB call ok, unprepared SQL ok.

			if ( null === $customer_data ) {
				return $data;
			}

			$customer_data = array_map( array( $this, 'cast_numbers' ), $customer_data );
			$data          = (object) array(
				'data'    => $customer_data,
				'total'   => $db_records_count,
				'pages'   => $total_pages,
				'page_no' => (int) $query_args['page'],
			);

			wp_cache_set( $cache_key, $data, $this->cache_group );
		}

		return $data;
	}

	/**
	 * Gets the customer ID for given order, either from user_id, if the order was done
	 * by a registered customer, or from the billing email in the provided WC_Order.
	 *
	 * @param WC_Order $order Order to get customer data from.
	 * @return int|false The ID of the retrieved customer, or false when there is no such customer.
	 */
	public static function get_customer_id_from_order( $order ) {
		$user_id = $order->get_user_id();
		if ( $user_id ) {
			return self::get_customer_id_by_user_id( $user_id );
		}

		$email = $order->get_billing_email( 'edit' );

		if ( empty( $email ) ) {
			return false;
		}

		$guest_customer_id = self::get_customer_id_by_email( $email );

		if ( $guest_customer_id ) {
			return $guest_customer_id;
		}

		return false;
	}

	/**
	 * Gets the guest (no user_id) customer ID or creates a new one for
	 * the corresponding billing email in the provided WC_Order
	 *
	 * @param WC_Order $order Order to get/create guest customer data with.
	 * @return int|false The ID of the retrieved/created customer, or false on error.
	 */
	public function get_or_create_guest_customer_from_order( $order ) {
		global $wpdb;

		$email = $order->get_billing_email( 'edit' );

		if ( empty( $email ) ) {
			return false;
		}

		$existing_guest = $this->get_guest_by_email( $email );

		if ( $existing_guest ) {
			return $existing_guest['customer_id'];
		}

		$result = $wpdb->insert(
			$wpdb->prefix . self::TABLE_NAME,
			array(
				'first_name'       => $order->get_billing_first_name( 'edit' ),
				'last_name'        => $order->get_billing_last_name( 'edit' ),
				'email'            => $email,
				'city'             => $order->get_billing_city( 'edit' ),
				'postcode'         => $order->get_billing_postcode( 'edit' ),
				'country'          => $order->get_billing_country( 'edit' ),
				'date_last_active' => date( 'Y-m-d H:i:s', $order->get_date_created( 'edit' )->getTimestamp() ),
			),
			array(
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
			)
		);

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Retrieve a guest (no user_id) customer row by email.
	 *
	 * @param string $email Email address.
	 * @return false|array Customer array if found, boolean false if not.
	 */
	public function get_guest_by_email( $email ) {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$guest_row  = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE email = %s AND user_id IS NULL LIMIT 1",
				$email
			),
			ARRAY_A
		); // WPCS: unprepared SQL ok.

		if ( $guest_row ) {
			return $this->cast_numbers( $guest_row );
		}

		return false;
	}

	/**
	 * Retrieve a registered customer row by user_id.
	 *
	 * @param string|int $user_id User ID.
	 * @return false|array Customer array if found, boolean false if not.
	 */
	public function get_customer_by_user_id( $user_id ) {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$customer   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE user_id = %d LIMIT 1",
				$user_id
			),
			ARRAY_A
		); // WPCS: unprepared SQL ok.

		if ( $customer ) {
			return $this->cast_numbers( $customer );
		}

		return false;
	}

	/**
	 * Retrieve a registered customer row id by user_id.
	 *
	 * @param string|int $user_id User ID.
	 * @return false|int Customer ID if found, boolean false if not.
	 */
	public static function get_customer_id_by_user_id( $user_id ) {
		global $wpdb;

		$table_name  = $wpdb->prefix . self::TABLE_NAME;
		$customer_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT customer_id FROM {$table_name} WHERE user_id = %d LIMIT 1",
				$user_id
			)
		); // WPCS: unprepared SQL ok.

		return $customer_id ? (int) $customer_id : false;
	}

	/**
	 * Retrieve a customer id by billing email.
	 *
	 * @param string $email Billing email for the user.
	 * @return false|int Customer ID if found, boolean false if not.
	 */
	public static function get_customer_id_by_email( $email ) {
		global $wpdb;

		$table_name  = $wpdb->prefix . self::TABLE_NAME;
		$customer_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT customer_id FROM {$table_name} WHERE email = %s AND user_id IS NULL LIMIT 1",
				$email
			)
		); // WPCS: unprepared SQL ok.

		return $customer_id ? (int) $customer_id : false;
	}

	/**
	 * Update the database with customer data.
	 *
	 * @param int $user_id WP User ID to update customer data for.
	 * @return int|bool|null Number or rows modified or false on failure.
	 */
	public static function update_registered_customer( $user_id ) {
		global $wpdb;

		$customer = new WC_Customer( $user_id );

		if ( $customer->get_id() != $user_id ) {
			return false;
		}

		$last_active = $customer->get_meta( 'wc_last_active', true, 'edit' );
		$data        = array(
			'user_id'          => $user_id,
			'username'         => $customer->get_username( 'edit' ),
			'first_name'       => $customer->get_first_name( 'edit' ),
			'last_name'        => $customer->get_last_name( 'edit' ),
			'email'            => $customer->get_email( 'edit' ),
			'city'             => $customer->get_billing_city( 'edit' ),
			'postcode'         => $customer->get_billing_postcode( 'edit' ),
			'country'          => $customer->get_billing_country( 'edit' ),
			'date_registered'  => date( 'Y-m-d H:i:s', $customer->get_date_created( 'edit' )->getTimestamp() ),
			'date_last_active' => $last_active ? date( 'Y-m-d H:i:s', $last_active ) : null,
		);
		$format      = array(
			'%d',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
		);

		$customer_id = self::get_customer_id_by_user_id( $user_id );

		if ( $customer_id ) {
			// Preserve customer_id for existing user_id.
			$data['customer_id'] = $customer_id;
			$format[]            = '%d';
		}

		return $wpdb->replace( $wpdb->prefix . self::TABLE_NAME, $data, $format );
	}

	/**
	 * Returns string to be used as cache key for the data.
	 *
	 * @param array $params Query parameters.
	 * @return string
	 */
	protected function get_cache_key( $params ) {
		return 'woocommerce_' . self::TABLE_NAME . '_' . md5( wp_json_encode( $params ) );
	}

}
