/*
 * How the chat page reads a reply turn's answer, run by MailReplyTest.php in Node:
 *   node -e "<this file>" <path to chat-kit.js> <base64 of JSON { name: answer }>
 * Prints one JSON object: for each answer the kinds of card the page draws, the email card as the page reads it, the
 * addresses and the mail link the card's button gets, and every link the words beside the cards become.
 * Neutral data only.
 */
var K = require(process.argv[1]);
var answers = JSON.parse(Buffer.from(process.argv[2], 'base64').toString('utf8'));

/** Every link target in a parsed answer, whatever block or mark holds it. */
function links(node, found) {
  if (Array.isArray(node)) { node.forEach(function (n) { links(n, found); }); return found; }
  if (node && typeof node === 'object') {
    if (node.t === 'link' && typeof node.href === 'string') { found.push(node.href); }
    Object.keys(node).forEach(function (k) { links(node[k], found); });
  }
  return found;
}

var out = {};
Object.keys(answers).forEach(function (name) {
  var x = K.extractCards(answers[name], 'https://app.example.com');
  var email = x.cards.filter(function (c) { return c.k === 'email'; });
  var one = email[0] || null;
  out[name] = {
    kinds: x.cards.map(function (c) { return c.k; }),
    docs: x.docs.length,
    email: one,
    addresses: one ? K.emailAddresses(one.to) : [],
    mailto: one ? K.mailtoFor(one.to, one.subject, one.body).url.split('?')[0] : '',
    links: links(K.parseBlocks(x.text), [])
  };
});
// A draft whose words hold half a character pair cannot go into a mail link: the page must not lose the answer for it.
var threw = false;
try { K.mailtoFor('alex@example.com', 'Re: Your quote', 'Hi \ud83d there'); } catch (e) { threw = true; }
out.__mail_link_can_fail = threw;
process.stdout.write(JSON.stringify(out));
