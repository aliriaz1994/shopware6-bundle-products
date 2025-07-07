const { join, resolve } = require('path');

module.exports = () => {
    return {
        resolve: {
            alias: {
                '@digipercep-bundle': resolve(
                    join(__dirname, '..', 'src')
                )
            }
        }
    };
};