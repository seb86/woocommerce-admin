/**
 * External dependencies
 */

import { __ } from '@wordpress/i18n';
import { applyFilters } from '@wordpress/hooks';

/**
 * WooCommerce dependencies
 */
import { getAdminLink, getSetting } from '@woocommerce/wc-admin-settings';
import { updateQueryString } from '@woocommerce/navigation';

/**
 * Internal dependencies
 */
import Appearance from './tasks/appearance';
import Connect from './tasks/connect';
import { getProductIdsForCart } from 'dashboard/utils';
import Products from './tasks/products';
import Shipping from './tasks/shipping';
import Tax from './tasks/tax';
import Payments from './tasks/payments';
import { recordEvent } from 'lib/tracks';

export function getAllTasks( {
	profileItems,
	taskListPayments,
	query,
	toggleCartModal,
	installedPlugins,
} ) {
	const {
		hasPhysicalProducts,
		hasProducts,
		isAppearanceComplete,
		isTaxComplete,
		shippingZonesCount,
	} = getSetting( 'onboarding', {
		hasPhysicalProducts: false,
		hasProducts: false,
		isAppearanceComplete: false,
		isTaxComplete: false,
		shippingZonesCount: 0,
	} );

	const productIds = getProductIdsForCart(
		profileItems,
		true,
		installedPlugins
	);
	const remainingProductIds = getProductIdsForCart(
		profileItems,
		false,
		installedPlugins
	);

	const paymentsCompleted = Boolean(
		taskListPayments && taskListPayments.completed
	);
	const paymentsSkipped = Boolean(
		taskListPayments && taskListPayments.skipped
	);

	const {
		completed: profilerCompleted,
		items_purchased: itemsPurchased,
		product_types: productTypes,
		skipped: profilerSkipped,
		step: profilerStep,
		wccom_connected: wccomConnected,
	} = profileItems;

	const tasks = [
		{
			key: 'store_details',
			title: __( 'Store details', 'woocommerce-admin' ),
			container: null,
			onClick: () => {
				const lastStep = profilerStep ? `&step=${ profilerStep }` : '';
				recordEvent( 'tasklist_click', {
					task_name: 'store_details',
				} );
				window.location = getAdminLink(
					`admin.php?page=wc-admin&path=/profiler${ lastStep }`
				);
			},
			completed: profilerCompleted && ! profilerSkipped,
			visible: true,
			time: __( '4 minutes', 'woocommerce-admin' ),
		},
		{
			key: 'purchase',
			title: __( 'Purchase & install extensions', 'woocommerce-admin' ),
			container: null,
			onClick: () => {
				recordEvent( 'tasklist_click', {
					task_name: 'purchase',
				} );
				return remainingProductIds.length ? toggleCartModal() : null;
			},
			visible: productIds.length,
			completed: productIds.length && ! remainingProductIds.length,
			time: __( '2 minutes', 'woocommerce-admin' ),
		},
		{
			key: 'connect',
			title: __(
				'Connect your store to WooCommerce.com',
				'woocommerce-admin'
			),
			container: <Connect query={ query } />,
			onClick: () => {
				recordEvent( 'tasklist_click', {
					task_name: 'connect',
				} );
				updateQueryString( { task: 'connect' } );
			},
			visible: itemsPurchased && ! wccomConnected,
			completed: wccomConnected,
			time: __( '1 minute', 'woocommerce-admin' ),
		},
		{
			key: 'products',
			title: __( 'Add my products', 'woocommerce-admin' ),
			container: <Products />,
			onClick: () => {
				recordEvent( 'tasklist_click', {
					task_name: 'products',
				} );
				updateQueryString( { task: 'products' } );
			},
			completed: hasProducts,
			visible: true,
			time: __( '1 minute per product', 'woocommerce-admin' ),
		},
		{
			key: 'appearance',
			title: __( 'Personalize my store', 'woocommerce-admin' ),
			container: <Appearance />,
			onClick: () => {
				recordEvent( 'tasklist_click', {
					task_name: 'appearance',
				} );
				updateQueryString( { task: 'appearance' } );
			},
			completed: isAppearanceComplete,
			visible: true,
			time: __( '2 minutes', 'woocommerce-admin' ),
		},
		{
			key: 'shipping',
			title: __( 'Set up shipping', 'woocommerce-admin' ),
			container: <Shipping />,
			onClick: () => {
				recordEvent( 'tasklist_click', {
					task_name: 'shipping',
				} );
				updateQueryString( { task: 'shipping' } );
			},
			completed: shippingZonesCount > 0,
			visible:
				( productTypes && productTypes.includes( 'physical' ) ) ||
				hasPhysicalProducts,
			time: __( '1 minute', 'woocommerce-admin' ),
		},
		{
			key: 'tax',
			title: __( 'Set up tax', 'woocommerce-admin' ),
			container: <Tax />,
			onClick: () => {
				recordEvent( 'tasklist_click', {
					task_name: 'tax',
				} );
				updateQueryString( { task: 'tax' } );
			},
			completed: isTaxComplete,
			visible: true,
			time: __( '1 minute', 'woocommerce-admin' ),
		},
		{
			key: 'payments',
			title: __( 'Set up payments', 'woocommerce-admin' ),
			container: <Payments />,
			completed: paymentsCompleted || paymentsSkipped,
			onClick: () => {
				recordEvent( 'tasklist_click', {
					task_name: 'payments',
				} );
				if ( paymentsCompleted || paymentsSkipped ) {
					window.location = getAdminLink(
						'admin.php?page=wc-settings&tab=checkout'
					);
					return;
				}
				updateQueryString( { task: 'payments' } );
			},
			visible: true,
			time: __( '2 minutes', 'woocommerce-admin' ),
		},
	];

	return applyFilters(
		'woocommerce_admin_onboarding_task_list',
		tasks,
		query
	);
}
