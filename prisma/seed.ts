import { createExample } from "../src/lib/examples";

createExample()
  .then((id) => {
    console.log(`Exemplo criado: ${id}`);
    process.exit(0);
  })
  .catch((err) => {
    console.error(err);
    process.exit(1);
  });
