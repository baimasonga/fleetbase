// The proof a handover needs, checked on the phone before saving, so the officer
// sees what's missing (server/src/Support/Policy/RequirementTree.php and
// server/src/Support/Distribution/HandoverPolicy.php). The server checks again.

const RANK = { L1: 1, L2: 2, L3: 3 };

export function identityLevel({ cardValid, faceMatched, idMatched, photoTaken, witnessConfirmed }) {
  if (cardValid && faceMatched && idMatched) return 'L3';
  if (cardValid && faceMatched) return 'L2';
  if (photoTaken && witnessConfirmed) return 'L1';
  return null;
}

export function buildPolicy(base, { proxy, level, restricted }) {
  const extra = [];
  if (proxy || level === 'L1') extra.push({ type: 'witness_signature' });
  if (restricted) extra.push({ type: 'safety_ack' });
  const nodes = [];
  for (const tree of [base, ...extra]) {
    if (tree.all && Object.keys(tree).length === 1) nodes.push(...tree.all);
    else nodes.push(tree);
  }
  return nodes.length === 1 ? nodes[0] : { all: nodes };
}

const checkers = {
  '*': (leaf, items) => items.length >= (leaf.min || 1),
  signature: (leaf, items) => (leaf.require_name ? items.filter((i) => (i.name || '').trim()) : items).length >= (leaf.min || 1),
  beneficiary_id: (leaf, items) => items.some((i) => (RANK[i.level] || 0) >= (RANK[leaf.min_level || 'L1'] || 1) && (!leaf.methods || leaf.methods.includes(i.method))),
  lot_scan: (leaf, items, context) => {
    if (leaf.coverage !== 'all_lines') return items.length >= (leaf.min || 1);
    const lines = context.lines || [];
    if (!lines.length) return items.length >= 1;
    const scanned = new Set(items.map((i) => String(i.line)));
    return lines.every((line) => scanned.has(String(line.id)));
  },
  line_quantities: (leaf, items, context) => {
    if (!items.length) return false;
    const confirmed = new Map(items.map((i) => [String(i.line), Number(i.quantity)]));
    return (context.lines || []).every((line) => confirmed.has(String(line.id)) && !(leaf.match === 'entitlement' && line.entitled != null && confirmed.get(String(line.id)) > Number(line.entitled)));
  },
};

/** @returns {{satisfied: boolean, missing: object[]}} */
export function evaluate(tree, evidence, context = {}) {
  const byType = {};
  for (const item of evidence) (byType[item.type] ||= []).push(item);
  const missing = [];
  const node = (n, out) => {
    if (n.all) {
      let ok = true;
      for (const child of n.all) ok = node(child, out) && ok;
      return ok;
    }
    if (n.any) {
      for (const child of n.any) if (node(child, [])) return true;
      out.push({ any: n.any });
      return false;
    }
    const ok = (checkers[n.type] || checkers['*'])(n, byType[n.type] || [], context);
    if (!ok) out.push(n);
    return ok;
  };
  const satisfied = node(tree, missing);
  return { satisfied, missing: satisfied ? [] : missing };
}

const LABELS = {
  beneficiary_id: 'Confirm who the farmer is',
  line_quantities: 'Confirm the quantities',
  lot_scan: 'Choose the lot for every item',
  photo: 'Take a photo of the farmer with the inputs',
  signature: 'Farmer signs',
  mark: 'Farmer makes a mark',
  thumbprint_image: 'Thumbprint photo',
  witness_signature: 'Witness signs (needed for a proxy or a farmer without a card)',
  safety_ack: 'Confirm the pesticide safety briefing',
};

export function describe(node) {
  if (node.any) return node.any.map(describe).join(', or ');
  return LABELS[node.type] || node.type.replace(/_/g, ' ');
}
