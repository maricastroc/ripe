import { createExample } from "../src/lib/examples";

createExample()
  .then((id) => {
    console.log(`Example created: ${id}`);
    process.exit(0);
  })
  .catch((err) => {
    console.error(err);
    process.exit(1);
  });
