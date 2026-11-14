import type { SelectOption } from '@/components/form';

/** A category as the pickers receive it: subcategories name their parent. */
export interface TreeCategory {
    id: string;
    name: string;
    parentId?: string | null;
    parentName: string | null;
}

/**
 * The options of a category picker as a tree (forum#108): each subcategory right under its
 * category, indented by FormSelect. A subcategory whose category is not offered still goes under
 * its name, shown as a row that cannot be picked, so it never looks like a category of its own.
 */
export function categoryTreeOptions(categories: readonly TreeCategory[]): SelectOption[] {
    const parentKey = (category: TreeCategory): string | null =>
        category.parentId ?? (category.parentName === null ? null : `name:${category.parentName}`);

    const tops = categories.filter((category) => parentKey(category) === null);
    const children = new Map<string, TreeCategory[]>();
    for (const category of categories) {
        const key = parentKey(category);
        if (key !== null) {
            children.set(key, [...(children.get(key) ?? []), category]);
        }
    }

    const options: SelectOption[] = [];
    const placed = new Set<string>();
    const addChildren = (key: string): void => {
        for (const child of children.get(key) ?? []) {
            options.push({ value: child.id, label: child.name, level: 1 });
        }
        placed.add(key);
    };

    for (const top of tops) {
        options.push({ value: top.id, label: top.name });
        addChildren(top.id);
        addChildren(`name:${top.name}`);
    }

    // Subcategories of a category that is not offered, under its name
    for (const [key, list] of children) {
        if (placed.has(key)) {
            continue;
        }
        const first = list[0];
        if (first === undefined) {
            continue;
        }
        options.push({ value: `parent:${key}`, label: first.parentName ?? '', disabled: true });
        addChildren(key);
    }

    return options;
}
