export async function refreshGroupCatalog(api, store, accountId) {
  const groups = await api.getAllGroups();
  const groupIds = Object.keys(groups.gridVerMap ?? {});
  if (groupIds.length === 0) return store.writeGroupCatalog(accountId, []);

  const details = await api.getGroupInfo(groupIds);
  return store.writeGroupCatalog(accountId, groupIds.map((id) => {
    const group = details.gridInfoMap?.[id] ?? {};
    return { id, name: group.name || group.groupName || `Group ${id}` };
  }));
}
