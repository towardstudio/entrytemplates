const path = require("path");

module.exports = [
	{
		entry: {
			main: "./src/resources/js/index.js",
		},
		output: {
			filename: "index.min.js",
			path: path.resolve(__dirname, "src/resources/js/dist"),
		},
		mode: "development",
		devtool: false,
		resolve: {
			extensions: [".js"],
		},
		optimization: {
			minimize: true,
		},
	},
	{
		entry: {
			main: "./src/resources/js/modal.js",
		},
		output: {
			filename: "modal.min.js",
			path: path.resolve(__dirname, "src/resources/js/dist"),
		},
		mode: "development",
		devtool: false,
		resolve: {
			extensions: [".js"],
		},
		optimization: {
			minimize: true,
		},
	},
	// {
	// 	entry: {
	// 		main: "./src/resources/js/preview.js",
	// 	},
	// 	output: {
	// 		filename: "preview.min.js",
	// 		path: path.resolve(__dirname, "src/resources/js/dist"),
	// 	},
	// 	mode: "development",
	// 	devtool: false,
	// 	resolve: {
	// 		extensions: [".js"],
	// 	},
	// 	optimization: {
	// 		minimize: true,
	// 	},
	// },
];
