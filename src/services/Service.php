<?php
namespace verbb\manytomany\services;

use verbb\manytomany\fields\ManyToManyField;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\models\Section;

class Service extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * Returns related entries from an element limited to a section.
     *
     * @param ElementInterface $element
     * @param Section $section
     * @param string $field
     *
     * @return Entry[]
     */
    public function getRelatedEntries(ElementInterface $element, Section $section, string $fieldUid): array
    {
        $query = Entry::find();

        $fieldId = Db::idByUid(Table::FIELDS, $fieldUid);

        $query->section = $section;
        $query->limit = null;
        $query->status = null;
        $query->siteId = $element->siteId;
        $query->relatedTo = [
            'targetElement' => $element,
            'field' => $fieldId,
        ];

        return $query->all();
    }

    /**
     * Save relationships on external field.
     *
     * @param ManyToManyField $fieldType
     * @param ElementInterface $element
     */
    public function saveRelationship(ManyToManyField $fieldType, ElementInterface $element): void
    {
        // Get element ID of the current element. Be sure to use the canonicalId when dealing with autosave
        $targetId = $element->getCanonicalId();

        // Delete cache related to this element ID
        Craft::$app->getElements()->invalidateCachesForElement($element);

        // Get submitted field value - note that we're fetching `rawValue` not the normalized value
        // $content = $fieldType->rawValue;
        $cacheKey = implode('--', ['many-to-many', $fieldType->handle, $element->uid]);
        $content = Craft::$app->getCache()->get($cacheKey);
        Craft::$app->getCache()->delete($cacheKey);

        // There are 3 Items we need to make up a unique relationship in the craft_relations table:
        // fieldId  --> We define this in the Field settings when creating it
        // sourceId --> The elementIds that create the relationship initially. This is currently stored in the $content array
        // targetId --> $elementId, this is the reverse of the relationship
        $fieldId = Db::idByUid(Table::FIELDS, $fieldType->singleField);

        // The relationships we either want to add or leave
        $toAdd = $content['add'] ?? [];

        // The relationships we want to remove
        $toDelete = $content['delete'] ?? [];

        // First handle adding or updating the relationships that have to exist
        if (is_array($toAdd)) {
            foreach ($toAdd as $sourceId) {
                // Check if relation exists
                $exists = (new Query())
                    ->select('id')
                    ->from('{{%relations}}')
                    ->where('[[fieldId]] = :fieldId', [':fieldId' => $fieldId])
                    ->andWhere('[[sourceId]] = :sourceId', [':sourceId' => $sourceId])
                    ->andWhere('[[targetId]] = :targetId', [':targetId' => $targetId])
                    ->exists();

                // Create relation if it does not exist
                if (!$exists) {
                    $columns = [
                        'fieldId' => $fieldId,
                        'sourceId' => $sourceId,
                        'sourceSiteId' => null,
                        'targetId' => $targetId,
                        'sortOrder' => 1,
                    ];

                    Db::insert('{{%relations}}', $columns);
                }
            }
        }

        // Now, delete the existing relationships if the user removed them.
        if (is_array($toDelete)) {
            foreach ($toDelete as $sourceId) {
                $oldRelationConditions = [
                    'and',
                    '[[fieldId]] = :fieldId',
                    '[[sourceId]] = :sourceId',
                    '[[targetId]] = :targetId',
                ];
                $oldRelationParams = [
                    ':fieldId' => $fieldId,
                    ':sourceId' => $sourceId,
                    ':targetId' => $targetId,
                ];

                Db::delete('{{%relations}}', $oldRelationConditions, $oldRelationParams);
            }
        }
    }
}
