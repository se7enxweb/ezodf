/**
 * 
 */
package org.openoffice.ezodfmenu.comp;

import java.io.InputStream;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;

import javax.swing.JOptionPane;

import org.xml.sax.InputSource;
import org.w3c.dom.*;

import com.sun.org.apache.xerces.internal.impl.xs.dom.DOMParser;

/**
 * @author hovik
 *
 */
public class ServerConnection {
	
	private static final String LoginPath = "ezrest/login";
	private static final String TopNodeListPath = "ezrest/ezodfGetTopNodeList";
	private static final String GetChildrenPath = "ezrest/ezodfGetChildren";
	private static final String GetNodeInfoPath = "ezrest/ezodfGetNodeInfo";
	private static final String FetchOONodePath = "ezrest/ezodfFetchOONode";
	private static final String PutOONodePath = "ezrest/ezodfPutOONode";
	private static final String ReplaceOONodePath = "ezrest/ezodfReplaceOONode";
	
	protected ServerInfo serverInfo;
	protected String sessionID;
	
	/**
	 * Constructor
	 * 
	 * @param Server info
	 */
	public ServerConnection( ServerInfo info )
	{
		this.serverInfo = info;
	}
	
	/**
	 * Connect to server
	 * 
	 * @return True if connection is created. The connection properties is stored in this object.
	 */
	public boolean connect()
	{		
		// Log in.
		return this.login();
	}

	/**
	 * Login selected connection.
	 * 
	 * @return True if successfully logged in.
	 */
	protected boolean login()
	{
		HttpURLConnection connection;
		try
		{
			URL url = new URL( getLoginURL() );
			connection = (HttpURLConnection)url.openConnection();
		}
		catch( Exception e )
		{
			JOptionPane.showMessageDialog( null,
				    "Failed to open a connection: " + e.getMessage(),
				    "Connect",
				    JOptionPane.WARNING_MESSAGE);
			return false;
		}
		
		connection.setDoInput( true );
		connection.setDoOutput( true );
		
		try 
		{
			// Prepare request
			connection.setRequestMethod( "GET" );
			
			// Send request.
			OutputStream out = connection.getOutputStream();
			out.flush();
			out.close();
			
			// Read XML response.
			DOMParser parser = new DOMParser();
			InputStream in = connection.getInputStream();
			InputSource source = new InputSource(in);
			parser.parse(source);
			in.close();
			connection.disconnect();
			
		}
		catch( Exception e )
		{
			JOptionPane.showMessageDialog( null,
				    "Failed logging in: " + e.getMessage(),
				    "Login",
				    JOptionPane.WARNING_MESSAGE);
		}
		
		
		return true;
	}
	
	/**
	 * Get eZ Publish top node. The top node also contains top node list.
	 */
	public eZPTreeNode getTopNode()
	{
		return null;
	}


	/**
	 * Get login URL
	 * 
	 * @return Login URL
	 */
	protected String getLoginURL()
	{
		return serverInfo.getUrl() + ServerConnection.LoginPath + "?login=" + serverInfo.getUsername() + "&password=" + serverInfo.getPassword(); 	
	}
}