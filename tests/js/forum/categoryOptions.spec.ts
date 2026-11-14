import { describe, expect, it } from 'vitest';
import { categoryTreeOptions } from '../../../resources/js/forum/categoryOptions';

describe('categoryTreeOptions (forum#108)', () => {
    it('puts each subcategory right under its category', () => {
        expect(
            categoryTreeOptions([
                { id: 'g', name: 'General', parentId: null, parentName: null },
                { id: 'c', name: 'Campañas', parentId: null, parentName: null },
                { id: 'c1', name: 'Dune', parentId: 'c', parentName: 'Campañas' },
                { id: 'm', name: 'Mesas', parentId: null, parentName: null },
                { id: 'c2', name: '7º Mar', parentId: 'c', parentName: 'Campañas' },
            ])
        ).toEqual([
            { value: 'g', label: 'General' },
            { value: 'c', label: 'Campañas' },
            { value: 'c1', label: 'Dune', level: 1 },
            { value: 'c2', label: '7º Mar', level: 1 },
            { value: 'm', label: 'Mesas' },
        ]);
    });

    it('matches a parent by name when only the name is given', () => {
        expect(
            categoryTreeOptions([
                { id: 'c', name: 'Campañas', parentName: null },
                { id: 'c1', name: 'Dune', parentName: 'Campañas' },
            ])
        ).toEqual([
            { value: 'c', label: 'Campañas' },
            { value: 'c1', label: 'Dune', level: 1 },
        ]);
    });

    it('shows a category that is not offered as a row that cannot be picked', () => {
        expect(
            categoryTreeOptions([
                { id: 'g', name: 'General', parentId: null, parentName: null },
                { id: 'c1', name: 'Dune', parentId: 'c', parentName: 'Campañas' },
            ])
        ).toEqual([
            { value: 'g', label: 'General' },
            { value: 'parent:c', label: 'Campañas', disabled: true },
            { value: 'c1', label: 'Dune', level: 1 },
        ]);
    });
});
