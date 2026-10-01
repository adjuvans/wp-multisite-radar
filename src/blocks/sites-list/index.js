import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import Edit from './edit';
import './style.scss';

// Bloc dynamique : le rendu est fait en PHP (SitesMenu\Block::render()).
registerBlockType( metadata.name, { edit: Edit, save: () => null } );
