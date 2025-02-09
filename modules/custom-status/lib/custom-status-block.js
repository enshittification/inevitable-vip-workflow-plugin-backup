import './editor.scss';

import { PanelBody } from '@wordpress/components';
import { compose, useViewportMatch } from '@wordpress/compose';
import { dispatch, withDispatch, withSelect } from '@wordpress/data';
import { PluginSidebar } from '@wordpress/edit-post';
import { store as editorStore } from '@wordpress/editor';
import { useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';
import clsx from 'clsx';
import { useEffect } from 'react';

import CustomStatusSidebar from './components/custom-status-sidebar';
import useInterceptPluginSidebar from './hooks/use-intercept-plugin-sidebar';

const pluginName = 'vip-workflow-custom-status';
const sidebarName = 'vip-workflow-sidebar';

// Plugin sidebar
const CustomSaveButtonSidebar = ( {
	postType,
	savedStatus,
	editedStatus,
	isUnsavedPost,
	isSavingPost,
	onUpdateStatus,
} ) => {
	const savedStatusTerm = useMemo( () => getStatusTermFromSlug( savedStatus ), [ savedStatus ] );
	const nextStatusTerm = useMemo( () => getNextStatusTerm( savedStatus ), [ savedStatus ] );
	const isWideViewport = useViewportMatch( 'wide', '>=' );

	const isCustomSaveButtonVisible = useMemo(
		() => isCustomSaveButtonEnabled( isUnsavedPost, postType, savedStatus ),
		[ isUnsavedPost, postType, savedStatus ]
	);

	const isSidebarActionRequired = useMemo(
		() => isSidebarActionEnabled( savedStatusTerm, nextStatusTerm ),
		[ savedStatusTerm, nextStatusTerm ]
	);

	const isCustomSaveButtonDisabled = isSavingPost;

	// Selectively remove the native save button when publish guard and workflow statuses are in use
	useEffect( () => {
		if ( VW_CUSTOM_STATUSES.is_publish_guard_enabled ) {
			const editor = document.querySelector( '#editor' );

			if ( isCustomSaveButtonVisible ) {
				editor.classList.add( 'disable-native-save-button' );
			} else {
				editor.classList.remove( 'disable-native-save-button' );
			}
		} else {
			// Allow both buttons to coexist when publish guard is disabled
		}
	}, [ isCustomSaveButtonVisible ] );

	useInterceptPluginSidebar(
		`${ pluginName }/${ sidebarName }`,
		( isSidebarActive, toggleSidebar ) => {
			if ( isCustomSaveButtonDisabled ) {
				// Don't do anything
			} else if ( isSidebarActionRequired ) {
				// Open the sidebar if it's not already open
				// if ( ! isSidebarActive ) {
				toggleSidebar();
				// }
			} else if ( nextStatusTerm ) {
				onUpdateStatus( nextStatusTerm.slug );
				dispatch( editorStore ).savePost();
			}
		}
	);

	const buttonText = getCustomSaveButtonText( nextStatusTerm, isWideViewport );

	const InnerSaveButton = (
		<CustomInnerSaveButton
			buttonText={ buttonText }
			isSavingPost={ isSavingPost }
			isDisabled={ isCustomSaveButtonDisabled }
		/>
	);

	return (
		<>
			{ /* "Extended Post Status" in the sidebar */ }
			<CustomStatusSidebar
				postType={ postType }
				status={ editedStatus }
				onUpdateStatus={ onUpdateStatus }
			/>

			{ /* Custom save button in the toolbar */ }
			{ isCustomSaveButtonVisible && (
				<PluginSidebar name={ sidebarName } title={ buttonText } icon={ InnerSaveButton }>
					<SidebarContent savedStatusTerm={ savedStatusTerm } nextStatusTerm={ nextStatusTerm } />
				</PluginSidebar>
			) }
		</>
	);
};

const mapSelectProps = select => {
	const {
		getEditedPostAttribute,
		getCurrentPostAttribute,
		isSavingPost,
		getCurrentPost,
		getCurrentPostType,
	} = select( editorStore );

	const post = getCurrentPost();

	// Brand-new unsaved posts have the 'auto-draft' status.
	const isUnsavedPost = post?.status === 'auto-draft';

	return {
		// The status from the last saved post. Updates when a post has been successfully saved in the backend.
		savedStatus: getCurrentPostAttribute( 'status' ),

		// The status from the current post in the editor. Changes immediately when editPost() is dispatched in the UI,
		// before the post is updated in the backend.
		editedStatus: getEditedPostAttribute( 'status' ),

		postType: getCurrentPostType(),
		isSavingPost: isSavingPost(),
		isUnsavedPost,
	};
};

const mapDispatchStatusToProps = dispatch => {
	return {
		onUpdateStatus( status ) {
			const editPostOptions = {
				// When we change post status, don't add this change to the undo stack.
				// We don't want ctrl-z or the undo button in toolbar to rollback a post status change.
				undoIgnore: true,
			};

			dispatch( editorStore ).editPost( { status }, editPostOptions );
		},
	};
};

registerPlugin( pluginName, {
	render: compose(
		withSelect( mapSelectProps ),
		withDispatch( mapDispatchStatusToProps )
	)( CustomSaveButtonSidebar ),
} );

// Components

const CustomInnerSaveButton = ( { buttonText, isSavingPost, isDisabled } ) => {
	const isTinyViewport = useViewportMatch( 'small', '<' );

	const classNames = clsx( 'vip-workflow-save-button', {
		'is-busy': isSavingPost,
		'is-disabled': isDisabled,
		'is-tiny': isTinyViewport,
	} );

	return <div className={ classNames }>{ buttonText }</div>;
};

const isSidebarActionEnabled = ( savedStatusTerm, nextStatusTerm ) => {
	console.log( 'isSidebarActionEnabled:', { savedStatusTerm, nextStatusTerm } );

	return true;
};

const SidebarContent = ( { savedStatusTerm, nextStatusTerm } ) => {
	return (
		<PanelBody>
			<p>
				{ savedStatusTerm.name } is current, { nextStatusTerm.name } is next
			</p>
		</PanelBody>
	);
};

// Utility methods

const isCustomSaveButtonEnabled = ( isUnsavedPost, postType, statusSlug ) => {
	if ( isUnsavedPost ) {
		// Show native "Save" for new posts
		return false;
	}

	const isSupportedPostType = VW_CUSTOM_STATUSES.supported_post_types.includes( postType );

	// Exclude the last custom status. Show the regular editor button on the last step.
	const allButLastStatusTerm = VW_CUSTOM_STATUSES.status_terms.slice( 0, -1 );
	const isSupportedStatusTerm = allButLastStatusTerm.map( t => t.slug ).includes( statusSlug );

	return isSupportedPostType && isSupportedStatusTerm;
};

const getCustomSaveButtonText = ( nextStatusTerm, isWideViewport ) => {
	let buttonText = __( 'Save', 'vip-workflow' );

	if ( nextStatusTerm ) {
		const nextStatusName = nextStatusTerm.name;

		if ( isWideViewport ) {
			// translators: %s: Next custom status name, e.g. "Draft"
			buttonText = sprintf( __( 'Move to %s', 'vip-workflow' ), nextStatusName );
		} else {
			const truncatedStatus = truncateText( nextStatusName, 7 );

			// translators: %s: Next custom status name, possibly truncated with an ellipsis. e.g. "Draft" or "Pendi…"
			buttonText = sprintf( __( 'Move to %s', 'vip-workflow' ), truncatedStatus );
		}
	}

	return buttonText;
};

const getStatusTermFromSlug = statusSlug => {
	return VW_CUSTOM_STATUSES.status_terms.find( term => term.slug === statusSlug ) || false;
};

const getNextStatusTerm = currentStatus => {
	const currentIndex = VW_CUSTOM_STATUSES.status_terms.findIndex(
		term => term.slug === currentStatus
	);

	if ( -1 === currentIndex || currentIndex === VW_CUSTOM_STATUSES.status_terms.length - 1 ) {
		return false;
	}

	return VW_CUSTOM_STATUSES.status_terms[ currentIndex + 1 ];
};

const truncateText = ( text, length ) => {
	if ( text.length > length ) {
		return text.slice( 0, length ) + '…';
	}
	return text;
};
