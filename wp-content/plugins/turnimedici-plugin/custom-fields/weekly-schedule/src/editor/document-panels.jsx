import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { SelectControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { store as coreStore, useEntityProp } from '@wordpress/core-data';
import { store as editorStore } from '@wordpress/editor';

function RelationPanel({ postType, name, title, metaKey, relatedType }) {
    const currentType = useSelect((select) => select(editorStore).getCurrentPostType(), []);
    const [meta, setMeta] = useEntityProp('postType', currentType, 'meta');

    const related = useSelect(
        (select) => {
            if (currentType !== postType) return null;
            return select(coreStore).getEntityRecords('postType', relatedType, {
                per_page: 100,
                orderby: 'title',
                order: 'asc',
                status: 'publish,draft',
            });
        },
        [currentType, postType, relatedType]
    );

    if (currentType !== postType) return null;

    const options = [{ label: '— Nessuna —', value: 0 }].concat(
        (related || []).map((post) => ({
            label: post.title?.rendered || `#${post.id}`,
            value: post.id,
        }))
    );

    return (
        <PluginDocumentSettingPanel name={name} title={title}>
            <SelectControl
                value={meta?.[metaKey] || 0}
                options={options}
                onChange={(value) => setMeta({ ...meta, [metaKey]: parseInt(value, 10) || 0 })}
                __nextHasNoMarginBottom
            />
        </PluginDocumentSettingPanel>
    );
}

function TurnimediciPanels() {
    return (
        <>
            <RelationPanel
                postType="tm_facility"
                name="tm-aft"
                title="AFT di appartenenza"
                metaKey="tm_aft_id"
                relatedType="tm_aft"
            />
        </>
    );
}

registerPlugin('turnimedici-relations', { render: TurnimediciPanels });
